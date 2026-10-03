<?php
require_once __DIR__ . '/../admin-auth-guard.php';
require_once __DIR__ . '/../database/connect.php';
require_once __DIR__ . '/../database/sync-election-statuses.php';

if (!isset($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function escape(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function parseElectionDate(mixed $value): DateTimeImmutable|false
{
	if (!is_string($value)) {
		return false;
	}

	$date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value);
	$errors = DateTimeImmutable::getLastErrors();
	if (!$date || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d\\TH:i') !== $value) {
		return false;
	}

	return $date;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfToken = $_POST['csrf_token'] ?? '';
	$action = $_POST['action'] ?? '';
	$electionId = filter_input(INPUT_POST, 'election_id', FILTER_VALIDATE_INT) ?: 0;

	if (!is_string($csrfToken) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
		$error = 'Your session expired. Reload the page and try again.';
	} elseif (in_array($action, ['create', 'update'], true)) {
		$name = trim($_POST['election_name'] ?? '');
		$description = trim($_POST['description'] ?? '');
		$startDate = parseElectionDate($_POST['start_date'] ?? null);
		$endDate = parseElectionDate($_POST['end_date'] ?? null);
		$now = new DateTimeImmutable();

		if ($name === '' || strlen($name) > 150 || strlen($description) > 60000 || !$startDate || !$endDate || $endDate <= $now || $endDate <= $startDate || ($action === 'create' && $startDate <= $now)) {
			$error = $action === 'create'
				? 'Enter an election name and a future start/end schedule with the end after the start.'
				: 'Enter an election name and a valid schedule that ends in the future.';
		} else {
			$startSql = $startDate->format('Y-m-d H:i:s');
			$endSql = $endDate->format('Y-m-d H:i:s');
			$overlapCheck = $conn->prepare("SELECT election_id FROM elections WHERE status IN ('scheduled', 'ongoing') AND start_date < ? AND end_date > ? AND election_id <> ? LIMIT 1");
			$overlapCheck->bind_param('ssi', $endSql, $startSql, $electionId);
			$overlapCheck->execute();
			$overlappingElection = $overlapCheck->get_result()->fetch_assoc();
			$overlapCheck->close();

			$existingElection = null;
			if ($action === 'update' && !$overlappingElection) {
				$existingCheck = $conn->prepare('SELECT election_name FROM elections WHERE election_id = ? LIMIT 1');
				$existingCheck->bind_param('i', $electionId);
				$existingCheck->execute();
				$existingElection = $existingCheck->get_result()->fetch_assoc();
				$existingCheck->close();
			}

			if ($action === 'update' && !$existingElection) {
				$error = 'That election could not be found.';
			} elseif ($overlappingElection) {
				$error = 'This schedule overlaps another scheduled or ongoing election.';
			} else {
				try {
					$conn->begin_transaction();
					if ($action === 'create') {
						$stmt = $conn->prepare("INSERT INTO elections (election_name, description, start_date, end_date, status) VALUES (?, NULLIF(?, ''), ?, ?, 'scheduled')");
						$stmt->bind_param('ssss', $name, $description, $startSql, $endSql);
						$stmt->execute();
						$createdElectionId = $conn->insert_id;
						$stmt->close();
						$logAction = 'Created election';
						$logDescription = $name;
					} else {
						$newStatus = $startDate <= $now ? 'ongoing' : 'scheduled';
						$update = $conn->prepare('UPDATE elections SET election_name = ?, description = NULLIF(?, \'\'), start_date = ?, end_date = ?, status = ? WHERE election_id = ?');
						$update->bind_param('sssssi', $name, $description, $startSql, $endSql, $newStatus, $electionId);
						$update->execute();
						$update->close();
						$createdElectionId = $electionId;
						$logAction = 'Updated election';
						$logDescription = "{$existingElection['election_name']} -> {$name}";
					}

					$adminId = (int) $_SESSION['account_id'];
					$log = $conn->prepare('INSERT INTO activity_logs (admin_id, action, description) VALUES (?, ?, ?)');
					$log->bind_param('iss', $adminId, $logAction, $logDescription);
					$log->execute();
					$log->close();
					$conn->commit();
					$conn->close();
					header('Location: election-status.php?' . ($action === 'create' ? 'created=' : 'updated_schedule=') . $createdElectionId);
					exit;
				} catch (mysqli_sql_exception $exception) {
					$conn->rollback();
					error_log($exception->getMessage());
					$error = 'The election could not be saved. Please check the schedule and try again.';
				}
			}
		}
	} elseif ($action === 'delete' && $electionId > 0) {
		$electionQuery = $conn->prepare('SELECT election_name FROM elections WHERE election_id = ? LIMIT 1');
		$electionQuery->bind_param('i', $electionId);
		$electionQuery->execute();
		$election = $electionQuery->get_result()->fetch_assoc();
		$electionQuery->close();

		if (!$election) {
			$error = 'That election could not be found.';
		} else {
			try {
				$conn->begin_transaction();
				$adminId = (int) $_SESSION['account_id'];
				$description = "Deleted election {$election['election_name']} (ID {$electionId})";
				$log = $conn->prepare("INSERT INTO activity_logs (admin_id, action, description) VALUES (?, 'Deleted election', ?)");
				$log->bind_param('is', $adminId, $description);
				$log->execute();
				$log->close();

				$delete = $conn->prepare('DELETE FROM elections WHERE election_id = ?');
				$delete->bind_param('i', $electionId);
				$delete->execute();
				$deleted = $delete->affected_rows;
				$delete->close();
				if ($deleted !== 1) {
					$conn->rollback();
					$error = 'That election could not be deleted.';
				} else {
					$conn->commit();
					$conn->close();
					header('Location: election-status.php?deleted=1');
					exit;
				}
			} catch (mysqli_sql_exception $exception) {
				$conn->rollback();
				error_log($exception->getMessage());
				$error = 'The election and its related records could not be deleted.';
			}
		}
	} elseif (in_array($action, ['open', 'close'], true) && $electionId > 0) {
		$electionQuery = $conn->prepare('SELECT election_name, start_date, end_date, status FROM elections WHERE election_id = ? LIMIT 1');
		$electionQuery->bind_param('i', $electionId);
		$electionQuery->execute();
		$election = $electionQuery->get_result()->fetch_assoc();
		$electionQuery->close();

		if (!$election) {
			$error = 'That election could not be found.';
		} elseif ($action === 'open' && $election['status'] === 'scheduled' && strtotime($election['start_date']) <= time() && strtotime($election['end_date']) > time()) {
			$ongoingResult = $conn->query("SELECT election_id FROM elections WHERE status = 'ongoing' AND election_id <> {$electionId} LIMIT 1");
			if ($ongoingResult->fetch_assoc()) {
				$error = 'Another election is already open.';
			} else {
				$newStatus = 'ongoing';
			}
		} elseif ($action === 'close' && $election['status'] === 'ongoing') {
			$newStatus = 'ended';
		} else {
			$error = 'This election cannot be changed to that status at this time.';
		}

		if ($error === '' && isset($newStatus)) {
			try {
				$conn->begin_transaction();
				$update = $conn->prepare('UPDATE elections SET status = ? WHERE election_id = ?');
				$update->bind_param('si', $newStatus, $electionId);
				$update->execute();
				$update->close();

				$adminId = (int) $_SESSION['account_id'];
				$actionLabel = $newStatus === 'ongoing' ? 'Opened election' : 'Closed election';
				$log = $conn->prepare('INSERT INTO activity_logs (admin_id, action, description) VALUES (?, ?, ?)');
				$log->bind_param('iss', $adminId, $actionLabel, $election['election_name']);
				$log->execute();
				$log->close();
				$conn->commit();
				$conn->close();
				header('Location: election-status.php?updated=' . $newStatus);
				exit;
			} catch (mysqli_sql_exception $exception) {
				$conn->rollback();
				error_log($exception->getMessage());
				$error = 'The election status could not be updated.';
			}
		}
	} else {
		$error = 'Choose a valid election action.';
	}
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	syncElectionStatuses($conn);
}

$electionResult = $conn->query("SELECT e.election_id, e.election_name, e.description, e.start_date, e.end_date, e.status, (SELECT COUNT(*) FROM candidates c WHERE c.election_id = e.election_id) AS candidate_total, (SELECT COUNT(*) FROM votes v WHERE v.election_id = e.election_id) AS vote_total FROM elections e ORDER BY e.start_date DESC");
$elections = $electionResult->fetch_all(MYSQLI_ASSOC);
$ongoingElection = null;
foreach ($elections as $election) {
	if ($election['status'] === 'ongoing') {
		$ongoingElection = $election;
		break;
	}
}
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
	<title>Election Overview | CCS E-Voting System</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
	<link rel="stylesheet" href="../front_end/style.css?v=3">
</head>
<body class="admin-page">
	<div class="admin-layout">
		<aside class="admin-sidebar">
			<a class="admin-brand" href="admin-landing-page.php"><img src="../image/logo-css.jpg" alt=""><span><strong>CCS E-Voting</strong><small>ADMINISTRATION</small></span></a>
			<p class="admin-nav-label">WORKSPACE</p>
			<nav class="admin-nav" aria-label="Admin navigation">
				<a class="admin-nav-link" href="admin-landing-page.php"><i class="ti ti-layout-dashboard" aria-hidden="true"></i> Dashboard</a>
				<a class="admin-nav-link admin-nav-current" href="election-status.php"><i class="ti ti-calendar-event" aria-hidden="true"></i> Election overview</a>
				<a class="admin-nav-link" href="add-candidates.php"><i class="ti ti-user-plus" aria-hidden="true"></i> Add candidates</a>
				<a class="admin-nav-link" href="manage-voters.php"><i class="ti ti-users" aria-hidden="true"></i> Manage voters</a>
			</nav>
			<?php if ($ongoingElection): ?>
				<div class="admin-sidebar-election">
					<span>ONGOING ELECTION</span>
					<a href="add-candidates.php?election_id=<?= (int) $ongoingElection['election_id'] ?>"><?= escape($ongoingElection['election_name']) ?></a>
				</div>
			<?php endif; ?>
		</aside>
		<div class="admin-main">
			<header class="admin-topbar">
				<div><span class="admin-topbar-kicker">COLLEGE OF COMPUTER STUDIES</span><strong>Election management</strong></div>
				<div class="admin-user"><span class="admin-avatar"><?= escape($adminInitial) ?></span><span class="admin-user-name"><?= escape($adminName) ?><small>Administrator</small></span><a class="admin-logout" href="../landing-page/index.php?logout=1" aria-label="Log out" title="Log out"><i class="ti ti-logout-2" aria-hidden="true"></i></a></div>
			</header>
			<main class="admin-content">
				<section class="admin-welcome"><div><p class="admin-eyebrow">ELECTION CONTROL</p><h1>Election overview</h1><p>Create an election, review its schedule, and control when voting is open.</p></div><span class="admin-date"><i class="ti ti-calendar" aria-hidden="true"></i> <?= date('F j, Y') ?></span></section>
				<?php if ($error !== ''): ?><p class="admin-feedback admin-feedback-error" role="alert"><?= escape($error) ?></p><?php endif; ?>
				<?php if (isset($_GET['created'])): ?><p class="admin-feedback admin-feedback-success" role="status">Election created and scheduled.</p><?php endif; ?>
				<?php if (isset($_GET['updated'])): ?><p class="admin-feedback admin-feedback-success" role="status">Election status updated to <?= escape(ucfirst($_GET['updated'])) ?>.</p><?php endif; ?>
				<?php if (isset($_GET['updated_schedule'])): ?><p class="admin-feedback admin-feedback-success" role="status">Election details and schedule updated.</p><?php endif; ?>
				<?php if (isset($_GET['deleted'])): ?><p class="admin-feedback admin-feedback-success" role="status">Election and its related candidates and voting records deleted.</p><?php endif; ?>

				<section class="admin-work-panel">
					<div class="admin-work-heading"><div><p class="admin-eyebrow">NEW SCHEDULE</p><h2>Create election</h2></div><i class="ti ti-calendar-plus" aria-hidden="true"></i></div>
					<form class="admin-form-grid" method="post" action="election-status.php">
						<input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
						<input type="hidden" name="action" value="create">
						<div class="admin-form-field admin-form-field-wide"><label for="election_name">Election name <span>*</span></label><input id="election_name" name="election_name" type="text" maxlength="150" required></div>
						<div class="admin-form-field"><label for="start_date">Voting starts <span>*</span></label><input id="start_date" name="start_date" type="datetime-local" required></div>
						<div class="admin-form-field"><label for="end_date">Voting ends <span>*</span></label><input id="end_date" name="end_date" type="datetime-local" required></div>
						<div class="admin-form-field admin-form-field-wide"><label for="description">Description</label><textarea id="description" name="description" rows="3"></textarea></div>
						<button class="admin-primary-button admin-form-field-wide" type="submit"><i class="ti ti-calendar-plus" aria-hidden="true"></i> Create election</button>
					</form>
				</section>

				<section class="admin-work-panel admin-list-panel">
					<div class="admin-work-heading"><div><p class="admin-eyebrow">SCHEDULE AND STATUS</p><h2>All elections</h2></div><span class="admin-count"><?= count($elections) ?> total</span></div>
					<?php if ($elections): ?>
						<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Election</th><th>Schedule</th><th>Status</th><th>Candidates</th><th>Votes</th><th>Action</th></tr></thead><tbody>
						<?php foreach ($elections as $election): ?>
							<tr>
								<td><strong><?= escape($election['election_name']) ?></strong><?php if ($election['description']): ?><small class="admin-table-description"><?= escape($election['description']) ?></small><?php endif; ?></td>
								<td><span><?= escape(date('M j, Y g:i A', strtotime($election['start_date']))) ?></span><small class="admin-table-description">to <?= escape(date('M j, Y g:i A', strtotime($election['end_date']))) ?></small></td>
								<td><span class="admin-election-status status-<?= escape($election['status']) ?>"><span></span><?= escape(ucfirst($election['status'])) ?></span></td>
								<td><?= number_format((int) $election['candidate_total']) ?></td>
								<td><?= number_format((int) $election['vote_total']) ?></td>
								<td>
									<?php if ($election['status'] === 'scheduled' && strtotime($election['start_date']) <= time() && strtotime($election['end_date']) > time()): ?>
										<form method="post" action="election-status.php"><input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="open"><input type="hidden" name="election_id" value="<?= (int) $election['election_id'] ?>"><button class="admin-small-button" type="submit">Open voting</button></form>
									<?php elseif ($election['status'] === 'ongoing'): ?>
										<form method="post" action="election-status.php" onsubmit="return confirm('Close voting for this election now?')"><input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="close"><input type="hidden" name="election_id" value="<?= (int) $election['election_id'] ?>"><button class="admin-small-button admin-small-button-danger" type="submit">Close voting</button></form>
									<?php elseif ($election['status'] === 'scheduled'): ?><span class="admin-action-hint">Opens at start time</span><?php else: ?><span class="admin-action-hint">No action</span><?php endif; ?>
									<form class="admin-election-delete" method="post" action="election-status.php" onsubmit="return confirm('Permanently delete this election? Its candidates, positions, ballots, vote details, and receipts will also be deleted.')">
										<input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
										<input type="hidden" name="action" value="delete">
										<input type="hidden" name="election_id" value="<?= (int) $election['election_id'] ?>">
										<button class="admin-small-button admin-small-button-danger" type="submit">Delete election</button>
									</form>
								</td>
							</tr>
							<tr class="admin-election-editor-row">
								<td colspan="6">
									<details class="admin-election-edit">
										<summary><i class="ti ti-calendar-edit" aria-hidden="true"></i> Edit / reschedule <?= escape($election['election_name']) ?></summary>
										<form class="admin-election-edit-form" method="post" action="election-status.php">
											<input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
											<input type="hidden" name="action" value="update">
											<input type="hidden" name="election_id" value="<?= (int) $election['election_id'] ?>">
											<label class="admin-election-edit-wide"><span>Election name</span><input name="election_name" type="text" maxlength="150" value="<?= escape($election['election_name']) ?>" required></label>
											<label><span>Voting starts</span><input name="start_date" type="datetime-local" value="<?= escape(date('Y-m-d\TH:i', strtotime($election['start_date']))) ?>" required></label>
											<label><span>Voting ends</span><input name="end_date" type="datetime-local" value="<?= escape(date('Y-m-d\TH:i', strtotime($election['end_date']))) ?>" required></label>
											<label class="admin-election-edit-wide"><span>Description</span><textarea name="description" rows="2"><?= escape($election['description'] ?? '') ?></textarea></label>
											<button class="admin-small-button" type="submit">Save changes</button>
										</form>
									</details>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody></table></div>
					<?php else: ?><div class="admin-empty-election"><span><i class="ti ti-calendar-off" aria-hidden="true"></i></span><div><strong>No elections yet</strong><p>Create a schedule above to begin setting up an election.</p></div></div><?php endif; ?>
				</section>
			</main>
		</div>
	</div>
</body>
</html>
