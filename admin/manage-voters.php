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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfToken = $_POST['csrf_token'] ?? '';
	$voterId = filter_input(INPUT_POST, 'voter_id', FILTER_VALIDATE_INT) ?: 0;

	if (!is_string($csrfToken) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
		$error = 'Your session expired. Reload the page and try again.';
	} elseif ($voterId < 1) {
		$error = 'Choose a valid voter account.';
	} else {
		$voterQuery = $conn->prepare("SELECT (SELECT COUNT(*) FROM votes WHERE voter_id = accounts.account_id) AS vote_total FROM accounts WHERE account_id = ? AND role = 'voter' LIMIT 1");
		$voterQuery->bind_param('i', $voterId);
		$voterQuery->execute();
		$voter = $voterQuery->get_result()->fetch_assoc();
		$voterQuery->close();

		if (!$voter) {
			$error = 'That voter account could not be found.';
		} else {
			try {
				$conn->begin_transaction();
				$adminId = (int) $_SESSION['account_id'];
				$description = "Deleted voter account ID {$voterId} and {$voter['vote_total']} associated ballots";
				$log = $conn->prepare("INSERT INTO activity_logs (admin_id, action, description) VALUES (?, 'Deleted voter', ?)");
				$log->bind_param('is', $adminId, $description);
				$log->execute();
				$log->close();

				$delete = $conn->prepare("DELETE FROM accounts WHERE account_id = ? AND role = 'voter'");
				$delete->bind_param('i', $voterId);
				$delete->execute();
				$deleted = $delete->affected_rows;
				$delete->close();

				if ($deleted !== 1) {
					$conn->rollback();
					$error = 'That voter account could not be deleted.';
				} else {
					$conn->commit();
					$conn->close();
					header('Location: manage-voters.php?deleted=1');
					exit;
				}
			} catch (mysqli_sql_exception $exception) {
				$conn->rollback();
				error_log($exception->getMessage());
				$error = 'The voter account and associated votes could not be deleted.';
			}
		}
	}
}

$voterResult = $conn->query("
	SELECT a.account_id, a.student_id, a.fullname, a.email, a.course, a.year_level, a.status, a.created_at,
		(SELECT COUNT(*) FROM votes v WHERE v.voter_id = a.account_id) AS vote_total
	FROM accounts a
	WHERE a.role = 'voter'
	ORDER BY a.created_at DESC, a.account_id DESC
");
$voters = $voterResult->fetch_all(MYSQLI_ASSOC);
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
	<title>Manage Voters | CCS E-Voting System</title>
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
				<a class="admin-nav-link" href="election-status.php"><i class="ti ti-calendar-event" aria-hidden="true"></i> Election overview</a>
				<a class="admin-nav-link" href="add-candidates.php"><i class="ti ti-user-plus" aria-hidden="true"></i> Add candidates</a>
				<a class="admin-nav-link admin-nav-current" href="manage-voters.php"><i class="ti ti-users" aria-hidden="true"></i> Manage voters</a>
			</nav>
		</aside>
		<div class="admin-main">
			<header class="admin-topbar">
				<div><span class="admin-topbar-kicker">COLLEGE OF COMPUTER STUDIES</span><strong>Voter management</strong></div>
				<div class="admin-user"><span class="admin-avatar"><?= escape($adminInitial) ?></span><span class="admin-user-name"><?= escape($adminName) ?><small>Administrator</small></span><a class="admin-logout" href="../landing-page/index.php?logout=1" aria-label="Log out" title="Log out"><i class="ti ti-logout-2" aria-hidden="true"></i></a></div>
			</header>
			<main class="admin-content">
				<section class="admin-welcome">
					<div><p class="admin-eyebrow">REGISTERED ACCOUNTS</p><h1>Manage voters</h1><p>Review voter registrations and remove accounts that violate election rules.</p></div>
				</section>
				<?php if ($error !== ''): ?><p class="admin-feedback admin-feedback-error" role="alert"><?= escape($error) ?></p><?php endif; ?>
				<?php if (isset($_GET['deleted'])): ?><p class="admin-feedback admin-feedback-success" role="status">Voter account and its ballots deleted. Vote totals have been reduced.</p><?php endif; ?>

				<section class="admin-work-panel admin-list-panel">
					<div class="admin-work-heading"><div><p class="admin-eyebrow">VOTER ACCOUNTS</p><h2>Registered voters</h2></div><span class="admin-count"><?= count($voters) ?> total</span></div>
					<?php if ($voters): ?>
						<div class="admin-table-wrap">
							<table class="admin-table">
								<thead><tr><th>Name</th><th>Student ID</th><th>Email</th><th>Course</th><th>Year</th><th>Status</th><th>Votes</th><th>Registered</th><th>Action</th></tr></thead>
								<tbody>
									<?php foreach ($voters as $voter): ?>
										<tr>
											<td><strong><?= escape($voter['fullname']) ?></strong></td>
											<td><?= escape($voter['student_id'] ?? '') ?></td>
											<td><?= escape($voter['email']) ?></td>
											<td><?= escape($voter['course'] ?? '') ?></td>
											<td><?= escape($voter['year_level'] ?? '') ?></td>
											<td><span class="admin-table-status"><?= escape(ucfirst($voter['status'])) ?></span></td>
											<td><?= number_format((int) $voter['vote_total']) ?></td>
											<td><?= escape(date('M j, Y', strtotime($voter['created_at']))) ?></td>
											<td>
												<form method="post" action="manage-voters.php" onsubmit="return confirm('Permanently delete this voter account? Their ballots, vote details, and receipts will also be deleted, reducing election vote totals.')">
													<input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
													<input type="hidden" name="voter_id" value="<?= (int) $voter['account_id'] ?>">
													<button class="admin-small-button admin-small-button-danger" type="submit">Delete voter</button>
												</form>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php else: ?>
						<p class="admin-list-empty">No voter accounts have been registered yet.</p>
					<?php endif; ?>
				</section>
			</main>
		</div>
	</div>
</body>
</html>
