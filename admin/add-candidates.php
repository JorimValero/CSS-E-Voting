<?php
require_once __DIR__ . '/../admin-auth-guard.php';
require_once __DIR__ . '/../database/connect.php';

if (!isset($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function escape(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$error = '';
$selectedElectionId = filter_input(INPUT_GET, 'election_id', FILTER_VALIDATE_INT) ?: 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfToken = $_POST['csrf_token'] ?? '';
	$electionId = filter_input(INPUT_POST, 'election_id', FILTER_VALIDATE_INT) ?: 0;
	$positionId = filter_input(INPUT_POST, 'position_id', FILTER_VALIDATE_INT) ?: 0;
	$partyId = filter_input(INPUT_POST, 'party_id', FILTER_VALIDATE_INT) ?: 0;
	$fullname = trim($_POST['fullname'] ?? '');
	$studentId = trim($_POST['student_id'] ?? '');
	$yearLevel = trim($_POST['year_level'] ?? '');
	$course = trim($_POST['course'] ?? '');
	$photo = trim($_POST['photo'] ?? '');
	$biography = trim($_POST['biography'] ?? '');
	$platform = trim($_POST['platform'] ?? '');

	if (!is_string($csrfToken) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
		$error = 'Your session expired. Reload the page and try again.';
	} elseif ($fullname === '' || strlen($fullname) > 100 || strlen($studentId) > 30 || strlen($yearLevel) > 20 || strlen($course) > 100 || strlen($photo) > 255) {
		$error = 'Check that the candidate name, student ID, year level, course, and photo path fit their allowed lengths.';
	} else {
		$electionCheck = $conn->prepare("SELECT election_name FROM elections WHERE election_id = ? AND status IN ('draft', 'scheduled') AND start_date > NOW() LIMIT 1");
		$electionCheck->bind_param('i', $electionId);
		$electionCheck->execute();
		$selectedElection = $electionCheck->get_result()->fetch_assoc();
		$electionCheck->close();

		$positionCheck = $conn->prepare('SELECT position_id FROM positions WHERE position_id = ? AND election_id = ? LIMIT 1');
		$positionCheck->bind_param('ii', $positionId, $electionId);
		$positionCheck->execute();
		$selectedPosition = $positionCheck->get_result()->fetch_assoc();
		$positionCheck->close();

		if (!$selectedElection || !$selectedPosition) {
			$error = 'Choose a future draft or scheduled election and a position belonging to it.';
		} else {
			try {
				$conn->begin_transaction();
				$stmt = $conn->prepare("INSERT INTO candidates (election_id, position_id, party_id, fullname, student_id, year_level, course, photo, biography, platform, status) VALUES (?, ?, NULLIF(?, 0), ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), 'active')");
				$stmt->bind_param('iiisssssss', $electionId, $positionId, $partyId, $fullname, $studentId, $yearLevel, $course, $photo, $biography, $platform);
				$stmt->execute();
				$candidateId = $conn->insert_id;
				$stmt->close();

				$adminId = (int) $_SESSION['account_id'];
				$description = "Added candidate {$fullname} to election {$selectedElection['election_name']}";
				$log = $conn->prepare("INSERT INTO activity_logs (admin_id, action, description) VALUES (?, 'Added candidate', ?)");
				$log->bind_param('is', $adminId, $description);
				$log->execute();
				$log->close();
				$conn->commit();
				$conn->close();
				header('Location: add-candidates.php?election_id=' . $electionId . '&added=' . $candidateId);
				exit;
			} catch (mysqli_sql_exception $exception) {
				$conn->rollback();
				error_log($exception->getMessage());
				$error = 'The candidate could not be saved. Check the election, position, and party selections.';
			}
		}
	}
	$selectedElectionId = $electionId;
}

$electionResult = $conn->query("SELECT election_id, election_name, status, start_date FROM elections WHERE status IN ('draft', 'scheduled') AND start_date > NOW() ORDER BY start_date ASC");
$elections = $electionResult->fetch_all(MYSQLI_ASSOC);
if ($selectedElectionId === 0 && $elections) {
	$selectedElectionId = (int) $elections[0]['election_id'];
}

$positions = [];
$candidates = [];
if ($selectedElectionId > 0) {
	$positionQuery = $conn->prepare('SELECT position_id, position_name FROM positions WHERE election_id = ? ORDER BY position_order, position_name');
	$positionQuery->bind_param('i', $selectedElectionId);
	$positionQuery->execute();
	$positions = $positionQuery->get_result()->fetch_all(MYSQLI_ASSOC);
	$positionQuery->close();

	$candidateQuery = $conn->prepare('SELECT c.fullname, c.student_id, c.year_level, c.course, c.photo, c.status, p.position_name, pt.party_name FROM candidates c INNER JOIN positions p ON p.position_id = c.position_id LEFT JOIN parties pt ON pt.party_id = c.party_id WHERE c.election_id = ? ORDER BY p.position_order, p.position_name, c.fullname');
	$candidateQuery->bind_param('i', $selectedElectionId);
	$candidateQuery->execute();
	$candidates = $candidateQuery->get_result()->fetch_all(MYSQLI_ASSOC);
	$candidateQuery->close();
}

$partyResult = $conn->query('SELECT party_id, party_name FROM parties ORDER BY party_name');
$parties = $partyResult->fetch_all(MYSQLI_ASSOC);
$conn->close();

$adminName = $_SESSION['account_name'] ?? 'Administrator';
$adminInitial = strtoupper(substr($adminName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="theme-color" content="#f4fbfa">
	<title>Add Candidates | CCS E-Voting System</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
	<link rel="stylesheet" href="../front_end/style.css">
</head>
<body class="admin-page">
	<div class="admin-layout">
		<aside class="admin-sidebar">
			<a class="admin-brand" href="admin-landing-page.php"><img src="../image/logo-css.jpg" alt=""><span><strong>CCS E-Voting</strong><small>ADMINISTRATION</small></span></a>
			<p class="admin-nav-label">WORKSPACE</p>
			<nav class="admin-nav" aria-label="Admin navigation">
				<a class="admin-nav-link" href="admin-landing-page.php"><i class="ti ti-layout-dashboard" aria-hidden="true"></i> Dashboard</a>
				<a class="admin-nav-link" href="election-status.php"><i class="ti ti-calendar-event" aria-hidden="true"></i> Election overview</a>
				<a class="admin-nav-link admin-nav-current" href="add-candidates.php"><i class="ti ti-user-plus" aria-hidden="true"></i> Add candidates</a>
			</nav>
		</aside>
		<div class="admin-main">
			<header class="admin-topbar">
				<div><span class="admin-topbar-kicker">COLLEGE OF COMPUTER STUDIES</span><strong>Candidate management</strong></div>
				<div class="admin-user"><span class="admin-avatar"><?= escape($adminInitial) ?></span><span class="admin-user-name"><?= escape($adminName) ?><small>Administrator</small></span><a class="admin-logout" href="../landing-page/index.php?logout=1" aria-label="Log out" title="Log out"><i class="ti ti-logout-2" aria-hidden="true"></i></a></div>
			</header>
			<main class="admin-content">
				<section class="admin-welcome"><div><p class="admin-eyebrow">CANDIDATE MANAGEMENT</p><h1>Add candidates</h1><p>Assign each candidate to a position in a future election.</p></div></section>
				<?php if ($error !== ''): ?><p class="admin-feedback admin-feedback-error" role="alert"><?= escape($error) ?></p><?php endif; ?>
				<?php if (isset($_GET['added'])): ?><p class="admin-feedback admin-feedback-success" role="status">Candidate added successfully.</p><?php endif; ?>
				<section class="admin-work-panel">
					<div class="admin-work-heading"><div><p class="admin-eyebrow">CANDIDATE DETAILS</p><h2>Candidate information</h2></div><i class="ti ti-user-plus" aria-hidden="true"></i></div>
					<?php if (!$elections): ?>
						<div class="admin-empty-election"><span><i class="ti ti-calendar-off" aria-hidden="true"></i></span><div><strong>No future draft or scheduled elections</strong><p>Create an election and its positions before adding candidates.</p><a class="admin-inline-link" href="election-status.php">Go to election overview <i class="ti ti-arrow-right" aria-hidden="true"></i></a></div></div>
					<?php else: ?>
						<form class="admin-filter-form" method="get" action="add-candidates.php">
							<label for="election-filter">Election</label>
							<select id="election-filter" name="election_id" onchange="this.form.submit()">
								<?php foreach ($elections as $election): ?>
									<option value="<?= (int) $election['election_id'] ?>" <?= $selectedElectionId === (int) $election['election_id'] ? 'selected' : '' ?>><?= escape($election['election_name']) ?> (<?= escape(ucfirst($election['status'])) ?>)</option>
								<?php endforeach; ?>
							</select>
						</form>
						<?php if (!$positions): ?>
							<p class="admin-feedback admin-feedback-error">This election has no positions yet. Add positions before registering candidates.</p>
						<?php else: ?>
							<form class="admin-form-grid" method="post" action="add-candidates.php">
								<input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
								<input type="hidden" name="election_id" value="<?= $selectedElectionId ?>">
								<div class="admin-form-field"><label for="fullname">Candidate full name <span>*</span></label><input id="fullname" name="fullname" type="text" maxlength="100" required></div>
								<div class="admin-form-field"><label for="position_id">Position <span>*</span></label><select id="position_id" name="position_id" required><option value="">Select a position</option><?php foreach ($positions as $position): ?><option value="<?= (int) $position['position_id'] ?>"><?= escape($position['position_name']) ?></option><?php endforeach; ?></select></div>
								<div class="admin-form-field"><label for="student_id">Student ID</label><input id="student_id" name="student_id" type="text" maxlength="30"></div>
								<div class="admin-form-field"><label for="year_level">Year level</label><input id="year_level" name="year_level" type="text" maxlength="20" placeholder="e.g. 3rd Year"></div>
								<div class="admin-form-field"><label for="course">Course</label><input id="course" name="course" type="text" maxlength="100" placeholder="e.g. BS Computer Science"></div>
								<div class="admin-form-field"><label for="party_id">Party / organization</label><select id="party_id" name="party_id"><option value="0">Independent / none</option><?php foreach ($parties as $party): ?><option value="<?= (int) $party['party_id'] ?>"><?= escape($party['party_name']) ?></option><?php endforeach; ?></select></div>
								<div class="admin-form-field"><label for="photo">Photo path or URL</label><input id="photo" name="photo" type="text" maxlength="255" placeholder="image/candidate.jpg"></div>
								<div class="admin-form-field admin-form-field-wide"><label for="biography">Biography</label><textarea id="biography" name="biography" rows="3"></textarea></div>
								<div class="admin-form-field admin-form-field-wide"><label for="platform">Platform</label><textarea id="platform" name="platform" rows="4"></textarea></div>
								<button class="admin-primary-button admin-form-field-wide" type="submit"><i class="ti ti-user-plus" aria-hidden="true"></i> Add candidate</button>
							</form>
						<?php endif; ?>
					<?php endif; ?>
				</section>

				<section class="admin-work-panel admin-list-panel">
					<div class="admin-work-heading"><div><p class="admin-eyebrow">CURRENT ELECTION</p><h2>Registered candidates</h2></div><span class="admin-count"><?= count($candidates) ?> total</span></div>
					<?php if ($candidates): ?>
						<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Candidate</th><th>Student ID</th><th>Year level</th><th>Course</th><th>Position</th><th>Party</th><th>Status</th></tr></thead><tbody>
						<?php foreach ($candidates as $candidate): ?><tr><td><?= escape($candidate['fullname']) ?></td><td><?= escape($candidate['student_id'] ?? '') ?></td><td><?= escape($candidate['year_level'] ?? '') ?></td><td><?= escape($candidate['course'] ?? '') ?></td><td><?= escape($candidate['position_name']) ?></td><td><?= escape($candidate['party_name'] ?? 'Independent') ?></td><td><span class="admin-table-status"><?= escape(ucfirst($candidate['status'])) ?></span></td></tr><?php endforeach; ?>
						</tbody></table></div>
					<?php else: ?><p class="admin-list-empty">No candidates have been added to this election yet.</p><?php endif; ?>
				</section>
			</main>
		</div>
	</div>
</body>
</html>
