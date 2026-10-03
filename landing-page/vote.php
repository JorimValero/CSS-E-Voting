<?php
session_start();

if (($_SESSION['role'] ?? null) !== 'voter' || !isset($_SESSION['voter_id'])) {
	if (isset($_SESSION['account_id'])) {
		http_response_code(403);
		exit('Only logged-in voter accounts can cast ballots.');
	}

	header('Location: log-in.php?next=vote.php');
	exit;
}

if (!isset($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function escape(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$error = '';
$alreadyVoted = false;
$submitted = isset($_GET['submitted']);
$election = null;
$positions = [];
$candidatesByPosition = [];
$conn = null;

try {
	require_once __DIR__ . '/../database/connect.php';
	require_once __DIR__ . '/../database/sync-election-statuses.php';
	syncElectionStatuses($conn);
	$voterId = (int) $_SESSION['voter_id'];

	$accountQuery = $conn->prepare("SELECT account_id FROM accounts WHERE account_id = ? AND role = 'voter' AND status = 'active' LIMIT 1");
	$accountQuery->bind_param('i', $voterId);
	$accountQuery->execute();
	$activeVoter = $accountQuery->get_result()->fetch_assoc();
	$accountQuery->close();
	if (!$activeVoter) {
		$_SESSION = [];
		session_destroy();
		http_response_code(403);
		exit('This voter account is no longer active. Please contact an administrator.');
	}

	$electionQuery = $conn->query("SELECT election_id, election_name FROM elections WHERE status = 'ongoing' AND start_date <= NOW() AND end_date > NOW() ORDER BY start_date DESC LIMIT 1");
	$election = $electionQuery->fetch_assoc() ?: null;

	if ($election) {
		$electionId = (int) $election['election_id'];
		$positionQuery = $conn->prepare('SELECT position_id, position_name FROM positions WHERE election_id = ? ORDER BY position_order, position_name');
		$positionQuery->bind_param('i', $electionId);
		$positionQuery->execute();
		$positions = $positionQuery->get_result()->fetch_all(MYSQLI_ASSOC);
		$positionQuery->close();

		$candidateQuery = $conn->prepare("SELECT candidate_id, position_id, fullname, student_id, year_level, course, photo FROM candidates WHERE election_id = ? AND status = 'active' ORDER BY fullname");
		$candidateQuery->bind_param('i', $electionId);
		$candidateQuery->execute();
		foreach ($candidateQuery->get_result()->fetch_all(MYSQLI_ASSOC) as $candidate) {
			$candidatesByPosition[(int) $candidate['position_id']][] = $candidate;
		}
		$candidateQuery->close();

		$voteQuery = $conn->prepare('SELECT vote_id FROM votes WHERE election_id = ? AND voter_id = ? LIMIT 1');
		$voteQuery->bind_param('ii', $electionId, $voterId);
		$voteQuery->execute();
		$alreadyVoted = (bool) $voteQuery->get_result()->fetch_assoc();
		$voteQuery->close();
	}

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$csrfToken = $_POST['csrf_token'] ?? '';
		$postedElectionId = filter_input(INPUT_POST, 'election_id', FILTER_VALIDATE_INT) ?: 0;
		$postedChoices = $_POST['candidate'] ?? null;
		$choices = [];

		if (!is_string($csrfToken) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
			$error = 'Your session expired. Reload the page and try again.';
		} elseif (!$election || $postedElectionId !== (int) $election['election_id'] || !is_array($postedChoices)) {
			$error = 'Voting is not open for that election.';
		} elseif ($alreadyVoted) {
			$error = 'You have already voted in this election. Ballots cannot be changed.';
		} else {
			$validInput = true;
			foreach ($postedChoices as $positionKey => $candidateValue) {
				$positionId = filter_var($positionKey, FILTER_VALIDATE_INT);
				$candidateId = is_string($candidateValue) ? filter_var($candidateValue, FILTER_VALIDATE_INT) : false;
				if (!$positionId || !$candidateId || isset($choices[$positionId])) {
					$validInput = false;
					break;
				}
				$choices[$positionId] = $candidateId;
			}

			if (!$validInput || count($choices) !== count($positions)) {
				$error = 'Select exactly one candidate for every position.';
			} else {
				try {
					$conn->begin_transaction();

					$lockedElectionQuery = $conn->prepare("SELECT election_id FROM elections WHERE election_id = ? AND status = 'ongoing' AND start_date <= NOW() AND end_date > NOW() FOR UPDATE");
					$lockedElectionQuery->bind_param('i', $postedElectionId);
					$lockedElectionQuery->execute();
					$lockedElection = $lockedElectionQuery->get_result()->fetch_assoc();
					$lockedElectionQuery->close();

					if (!$lockedElection) {
						$conn->rollback();
						$error = 'Voting has closed or is not open for this election.';
					} else {
						$lockedPositionsQuery = $conn->prepare('SELECT position_id FROM positions WHERE election_id = ? FOR UPDATE');
						$lockedPositionsQuery->bind_param('i', $postedElectionId);
						$lockedPositionsQuery->execute();
						$lockedPositions = $lockedPositionsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
						$lockedPositionsQuery->close();

						$lockedCandidatesQuery = $conn->prepare("SELECT candidate_id, position_id FROM candidates WHERE election_id = ? AND status = 'active' FOR UPDATE");
						$lockedCandidatesQuery->bind_param('i', $postedElectionId);
						$lockedCandidatesQuery->execute();
						$lockedCandidates = $lockedCandidatesQuery->get_result()->fetch_all(MYSQLI_ASSOC);
						$lockedCandidatesQuery->close();

						$validPositions = array_map(static fn(array $position): int => (int) $position['position_id'], $lockedPositions);
						$validCandidates = [];
						foreach ($lockedCandidates as $candidate) {
							$validCandidates[(int) $candidate['position_id']][(int) $candidate['candidate_id']] = true;
						}

						$selectionValid = count($choices) === count($validPositions);
						foreach ($validPositions as $positionId) {
							if (!isset($choices[$positionId], $validCandidates[$positionId][$choices[$positionId]])) {
								$selectionValid = false;
								break;
							}
						}

						if (!$selectionValid || !$validPositions) {
							$conn->rollback();
							$error = 'Your selections no longer match the available candidates. Review the ballot and try again.';
						} else {
							$ballot = $conn->prepare('INSERT INTO votes (election_id, voter_id) VALUES (?, ?)');
							$ballot->bind_param('ii', $postedElectionId, $voterId);
							$ballot->execute();
							$voteId = $conn->insert_id;
							$ballot->close();

							$detail = $conn->prepare('INSERT INTO vote_details (vote_id, position_id, candidate_id) VALUES (?, ?, ?)');
							foreach ($choices as $positionId => $candidateId) {
								$detail->bind_param('iii', $voteId, $positionId, $candidateId);
								$detail->execute();
							}
							$detail->close();

							$conn->commit();
							$conn->close();
							header('Location: vote.php?submitted=1');
							exit;
						}
					}
				} catch (mysqli_sql_exception $exception) {
					$conn->rollback();
					if ($exception->getCode() === 1062) {
						$error = 'You have already voted in this election. Ballots cannot be changed.';
						$alreadyVoted = true;
					} else {
						error_log('Ballot submission error: ' . $exception->getMessage());
						$error = 'Your ballot could not be submitted. Please try again.';
					}
				}
			}
		}
	}

	$conn->close();
} catch (mysqli_sql_exception $exception) {
	error_log('Voting page database error: ' . $exception->getMessage());
	if ($conn instanceof mysqli) {
		$conn->close();
	}
	http_response_code(500);
	$error = 'Voting is temporarily unavailable. Please try again later.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="theme-color" content="#f4fbfa">
	<title>Cast Your Vote | CCS E-Voting System</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
	<link rel="stylesheet" href="../front_end/style.css?v=3">
</head>
<body>
	<header class="site-header">
		<a class="brand" href="index.php" aria-label="CCS E-Voting System home">
			<img class="brand-seal" src="../image/logo-css.jpg" alt="College of Computer Studies seal">
			<span class="brand-copy"><strong>CCS E-VOTING SYSTEM</strong><small>COLLEGE OF COMPUTER STUDIES</small></span>
		</a>
		<nav class="main-nav" aria-label="Main navigation">
			<a class="nav-link" href="index.php">Home</a>
			<a class="nav-link" href="candidates.php">Candidates</a>
			<a class="nav-link" href="results.php">Results</a>
		</nav>
		<div class="header-actions">
			<span class="signed-in-label">Hi, <?= escape($_SESSION['voter_name'] ?? 'Voter') ?></span>
			<a class="button button-register" href="index.php?logout=1">Log out</a>
		</div>
	</header>
	<main class="vote-main">
		<section class="candidates-intro">
			<p class="eyebrow">YOUR VOTE MATTERS</p>
			<h1>Cast your vote</h1>
			<?php if ($election): ?><p>Election: <strong><?= escape($election['election_name']) ?></strong></p><?php endif; ?>
		</section>

		<?php if ($error !== ''): ?><p class="auth-notice auth-error vote-notice" role="alert"><?= escape($error) ?></p><?php endif; ?>
		<?php if ($submitted || $alreadyVoted): ?>
			<section class="candidates-panel vote-state-panel">
				<div class="candidates-empty">
					<span class="candidates-empty-icon"><i class="ti ti-circle-check" aria-hidden="true"></i></span>
					<h2><?= $submitted ? 'Your ballot has been submitted' : 'You have already voted' ?></h2>
					<p>Your vote is final and cannot be changed. Thank you for participating.</p>
					<a class="button button-primary candidates-vote-button" href="results.php">View election updates</a>
				</div>
			</section>
		<?php elseif (!$election): ?>
			<section class="candidates-panel vote-state-panel">
				<div class="candidates-empty"><span class="candidates-empty-icon"><i class="ti ti-calendar-off" aria-hidden="true"></i></span><h2>Voting is not open</h2><p>There is no ongoing election accepting ballots right now.</p></div>
			</section>
		<?php elseif (!$positions): ?>
			<section class="candidates-panel vote-state-panel">
				<div class="candidates-empty"><span class="candidates-empty-icon"><i class="ti ti-list-details" aria-hidden="true"></i></span><h2>Ballot is not ready</h2><p>This election has no positions configured. Please contact an administrator.</p></div>
			</section>
		<?php else: ?>
			<form class="vote-form" method="post" action="vote.php" onsubmit="return confirm('This is your final decision. After you submit, you cannot edit or change your vote. Submit your ballot?')">
				<input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
				<input type="hidden" name="election_id" value="<?= (int) $election['election_id'] ?>">
				<?php $ballotReady = true; ?>
				<?php foreach ($positions as $position): ?>
					<?php $positionId = (int) $position['position_id']; $positionCandidates = $candidatesByPosition[$positionId] ?? []; if (!$positionCandidates) { $ballotReady = false; } ?>
					<fieldset class="vote-position">
						<legend><?= escape($position['position_name']) ?></legend>
						<?php if ($positionCandidates): ?>
							<div class="vote-candidate-list">
								<?php foreach ($positionCandidates as $candidate): ?>
									<label class="vote-candidate">
										<input type="radio" name="candidate[<?= $positionId ?>]" value="<?= (int) $candidate['candidate_id'] ?>" required>
										<span><strong><?= escape($candidate['fullname']) ?></strong><small><?= escape($candidate['course'] ?: 'Course not provided') ?><?= $candidate['year_level'] ? ' · ' . escape($candidate['year_level']) : '' ?></small></span>
									</label>
								<?php endforeach; ?>
							</div>
						<?php else: ?>
							<p class="position-empty">No active candidates are available for this position. Contact an administrator.</p>
						<?php endif; ?>
					</fieldset>
				<?php endforeach; ?>
				<div class="vote-warning" role="note"><i class="ti ti-alert-triangle" aria-hidden="true"></i><p><strong>Review carefully:</strong> once submitted, your ballot is final and cannot be edited or changed.</p></div>
				<button class="button button-primary vote-submit" type="submit" <?= !$ballotReady ? 'disabled' : '' ?>><i class="ti ti-vote" aria-hidden="true"></i> Submit final vote</button>
			</form>
		<?php endif; ?>
	</main>
	<footer class="site-footer"><p>CCS E-Voting System &copy; 2026 <span aria-hidden="true">|</span> College of Computer Studies</p></footer>
</body>
</html>
