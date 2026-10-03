<?php
session_start();

function escape(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$election = null;
$candidatesByPosition = [];
$databaseError = false;
$conn = null;

try {
	require_once __DIR__ . '/../database/connect.php';
	require_once __DIR__ . '/../database/sync-election-statuses.php';
	syncElectionStatuses($conn);

	$electionResult = $conn->query("SELECT election_id, election_name, status, start_date, end_date FROM elections WHERE status = 'ongoing' AND start_date <= NOW() AND end_date > NOW() ORDER BY start_date DESC LIMIT 1");
	$election = $electionResult->fetch_assoc() ?: null;

	if (!$election) {
		$electionResult = $conn->query("SELECT election_id, election_name, status, start_date, end_date FROM elections WHERE status = 'ended' ORDER BY end_date DESC, election_id DESC LIMIT 1");
		$election = $electionResult->fetch_assoc() ?: null;
	}

	if ($election) {
		$electionId = (int) $election['election_id'];
		if ($election['status'] === 'ended') {
			$candidateQuery = $conn->prepare("
				SELECT p.position_id, p.position_name, c.candidate_id, c.fullname, COUNT(v.vote_id) AS vote_count
				FROM positions p
				LEFT JOIN candidates c ON c.position_id = p.position_id AND c.election_id = p.election_id
				LEFT JOIN vote_details vd ON vd.candidate_id = c.candidate_id AND vd.position_id = p.position_id
				LEFT JOIN votes v ON v.vote_id = vd.vote_id AND v.election_id = p.election_id
				WHERE p.election_id = ?
				GROUP BY p.position_id, p.position_name, p.position_order, c.candidate_id, c.fullname
				ORDER BY p.position_order, p.position_name, vote_count DESC, c.fullname
			");
		} else {
			$candidateQuery = $conn->prepare("
				SELECT p.position_id, p.position_name, c.candidate_id, c.fullname
				FROM positions p
				LEFT JOIN candidates c ON c.position_id = p.position_id AND c.election_id = p.election_id AND c.status = 'active'
				WHERE p.election_id = ?
				ORDER BY p.position_order, p.position_name, c.fullname
			");
		}

		$candidateQuery->bind_param('i', $electionId);
		$candidateQuery->execute();
		foreach ($candidateQuery->get_result()->fetch_all(MYSQLI_ASSOC) as $candidate) {
			$positionId = (int) $candidate['position_id'];
			if (!isset($candidatesByPosition[$positionId])) {
				$candidatesByPosition[$positionId] = [
					'name' => $candidate['position_name'],
					'candidates' => [],
				];
			}
			if ($candidate['candidate_id'] !== null) {
				$candidatesByPosition[$positionId]['candidates'][] = $candidate;
			}
		}
		$candidateQuery->close();
	}

	$conn->close();
} catch (mysqli_sql_exception $exception) {
	error_log('Results page database error: ' . $exception->getMessage());
	$databaseError = true;
	if ($conn instanceof mysqli) {
		$conn->close();
	}
}

$voterName = $_SESSION['voter_name'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="theme-color" content="#f4fbfa">
	<title>Election Results | CCS E-Voting System</title>
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
			<a class="nav-link" href="index.php#about">About</a>
			<a class="nav-link" href="candidates.php">Candidates</a>
			<a class="nav-link is-active" href="results.php">Results</a>
		</nav>
		<div class="header-actions">
			<?php if ($voterName !== null): ?>
				<span class="signed-in-label">Hi, <?= escape($voterName) ?></span>
				<a class="button button-register" href="index.php?logout=1">Log out</a>
			<?php else: ?>
				<a class="button button-login" href="log-in.php"><i class="ti ti-user-filled" aria-hidden="true"></i> Login</a>
				<a class="button button-register" href="sign-up.php"><i class="ti ti-user-plus" aria-hidden="true"></i> Register</a>
			<?php endif; ?>
		</div>
		<details class="mobile-menu">
			<summary aria-label="Open navigation"><i class="ti ti-menu-2" aria-hidden="true"></i></summary>
			<nav aria-label="Mobile navigation">
				<a href="index.php">Home</a>
				<a href="index.php#about">About</a>
				<a href="candidates.php">Candidates</a>
				<a href="results.php" aria-current="page">Results</a>
				<?php if ($voterName !== null): ?>
					<a href="index.php?logout=1">Log out</a>
				<?php else: ?>
					<a href="log-in.php">Login</a>
					<a href="sign-up.php">Register</a>
				<?php endif; ?>
			</nav>
		</details>
	</header>

	<main class="candidates-main">
		<section class="candidates-intro">
			<p class="eyebrow">ELECTION OUTCOME</p>
			<h1>Election results</h1>
			<p>Vote totals are kept private while voting is open and published after the election ends.</p>
			<?php if ($election): ?>
				<span class="candidates-election"><i class="ti ti-calendar-event" aria-hidden="true"></i> <?= escape($election['election_name']) ?> &middot; <?= escape(ucfirst($election['status'])) ?></span>
			<?php endif; ?>
		</section>

		<section class="candidates-panel results-panel" aria-label="Election results">
			<?php if ($databaseError): ?>
				<div class="candidates-empty" role="alert"><span class="candidates-empty-icon"><i class="ti ti-database-exclamation" aria-hidden="true"></i></span><h2>Results are unavailable</h2><p>Please check the voting system database and try again.</p></div>
			<?php elseif (!$election): ?>
				<div class="candidates-empty"><span class="candidates-empty-icon"><i class="ti ti-chart-bar" aria-hidden="true"></i></span><h2>No results to show yet</h2><p>Results will appear here after an election has ended.</p></div>
			<?php elseif ($election['status'] === 'ongoing'): ?>
				<div class="results-privacy-notice"><i class="ti ti-lock" aria-hidden="true"></i><div><strong>Voting is still in progress</strong><p>Candidate names are shown below. Vote counts will be published after voting ends.</p></div></div>
				<?php foreach ($candidatesByPosition as $position): ?>
					<section class="results-position">
						<h2><?= escape($position['name']) ?></h2>
						<?php if ($position['candidates']): ?>
							<ul><?php foreach ($position['candidates'] as $candidate): ?><li><?= escape($candidate['fullname']) ?></li><?php endforeach; ?></ul>
						<?php else: ?>
							<p class="position-empty">No active candidates are listed for this position.</p>
						<?php endif; ?>
					</section>
				<?php endforeach; ?>
			<?php else: ?>
				<div class="results-privacy-notice results-published-notice"><i class="ti ti-rosette" aria-hidden="true"></i><div><strong>Official results</strong><p>This election has ended. Final vote counts are now available.</p></div></div>
				<?php foreach ($candidatesByPosition as $position): ?>
					<section class="results-position">
						<h2><?= escape($position['name']) ?></h2>
						<?php if ($position['candidates']): ?>
							<ol class="results-candidate-list">
								<?php foreach ($position['candidates'] as $candidate): ?>
									<li><strong><?= escape($candidate['fullname']) ?></strong><span><?= number_format((int) $candidate['vote_count']) ?> <?= (int) $candidate['vote_count'] === 1 ? 'vote' : 'votes' ?></span></li>
								<?php endforeach; ?>
							</ol>
						<?php else: ?>
							<p class="position-empty">No candidates were listed for this position.</p>
						<?php endif; ?>
					</section>
				<?php endforeach; ?>
			<?php endif; ?>
		</section>
	</main>
	<footer class="site-footer"><p>CCS E-Voting System &copy; 2026 <span aria-hidden="true">|</span> College of Computer Studies</p></footer>
</body>
</html>
