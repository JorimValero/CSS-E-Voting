<?php
require_once __DIR__ . '/../admin-auth-guard.php';
require_once __DIR__ . '/../database/connect.php';

function escape(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$voterResult = $conn->query("SELECT COUNT(*) AS total FROM accounts WHERE role = 'voter'");
$candidateResult = $conn->query('SELECT COUNT(*) AS total FROM candidates');
$positionResult = $conn->query('SELECT COUNT(*) AS total FROM positions');
$voteResult = $conn->query('SELECT COUNT(*) AS total FROM votes');

$totals = [
	'voters' => (int) $voterResult->fetch_assoc()['total'],
	'candidates' => (int) $candidateResult->fetch_assoc()['total'],
	'positions' => (int) $positionResult->fetch_assoc()['total'],
	'votes' => (int) $voteResult->fetch_assoc()['total'],
];

$electionResult = $conn->query('SELECT election_name, start_date, end_date, status FROM elections ORDER BY election_id DESC LIMIT 1');
$currentElection = $electionResult->fetch_assoc() ?: null;
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
	<title>Admin Dashboard | CCS E-Voting System</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
	<link rel="stylesheet" href="../front_end/style.css">
</head>
<body class="admin-page">
	<div class="admin-layout">
		<aside class="admin-sidebar">
			<a class="admin-brand" href="admin-landing-page.php">
				<img src="../image/logo-css.jpg" alt="">
				<span><strong>CCS E-Voting</strong><small>ADMINISTRATION</small></span>
			</a>
			<p class="admin-nav-label">WORKSPACE</p>
			<nav class="admin-nav" aria-label="Admin navigation">
				<a class="admin-nav-link admin-nav-current" href="admin-landing-page.php"><i class="ti ti-layout-dashboard" aria-hidden="true"></i> Dashboard</a>
				<a class="admin-nav-link" href="election-status.php"><i class="ti ti-calendar-event" aria-hidden="true"></i> Election overview</a>
				<a class="admin-nav-link" href="add-candidates.php"><i class="ti ti-user-plus" aria-hidden="true"></i> Add candidates</a>
			</nav>
		</aside>

		<div class="admin-main">
			<header class="admin-topbar">
				<div><span class="admin-topbar-kicker">COLLEGE OF COMPUTER STUDIES</span><strong>Election management</strong></div>
				<div class="admin-user">
					<span class="admin-avatar"><?= escape($adminInitial) ?></span>
					<span class="admin-user-name"><?= escape($adminName) ?><small>Administrator</small></span>
					<a class="admin-logout" href="../landing-page/index.php?logout=1" aria-label="Log out" title="Log out"><i class="ti ti-logout-2" aria-hidden="true"></i></a>
				</div>
			</header>

			<main class="admin-content">
				<section class="admin-welcome">
					<div><p class="admin-eyebrow">ADMIN DASHBOARD</p><h1>Good day, <?= escape($adminName) ?></h1><p>Here is the current overview of your voting system.</p></div>
					<span class="admin-date"><i class="ti ti-calendar" aria-hidden="true"></i> <?= date('F j, Y') ?></span>
				</section>

				<section class="admin-stats" aria-label="System totals">
					<article class="admin-stat"><span class="admin-stat-icon stat-voters"><i class="ti ti-users" aria-hidden="true"></i></span><p>Total voters</p><strong><?= number_format($totals['voters']) ?></strong><small>Voter accounts</small></article>
					<article class="admin-stat"><span class="admin-stat-icon stat-candidates"><i class="ti ti-user-star" aria-hidden="true"></i></span><p>Total candidates</p><strong><?= number_format($totals['candidates']) ?></strong><small>Across all elections</small></article>
					<article class="admin-stat"><span class="admin-stat-icon stat-positions"><i class="ti ti-list-check" aria-hidden="true"></i></span><p>Total positions</p><strong><?= number_format($totals['positions']) ?></strong><small>Configured positions</small></article>
					<article class="admin-stat"><span class="admin-stat-icon stat-votes"><i class="ti ti-vote" aria-hidden="true"></i></span><p>Total votes</p><strong><?= number_format($totals['votes']) ?></strong><small>Submitted ballots</small></article>
				</section>

				<section class="admin-election-panel" id="election-overview">
					<div class="admin-panel-heading"><div><p class="admin-eyebrow">SCHEDULE AND STATUS</p><h2>Election overview</h2></div><i class="ti ti-calendar-stats" aria-hidden="true"></i></div>
					<?php if ($currentElection): ?>
						<div class="admin-election-details">
							<div><span class="admin-detail-label">LATEST ELECTION</span><h3><?= escape($currentElection['election_name']) ?></h3></div>
							<span class="admin-election-status status-<?= escape($currentElection['status']) ?>"><span></span><?= escape(ucfirst($currentElection['status'])) ?></span>
						</div>
						<div class="admin-election-dates">
							<p><span>Voting starts</span><strong><?= escape(date('M j, Y, g:i A', strtotime($currentElection['start_date']))) ?></strong></p>
							<p><span>Voting ends</span><strong><?= escape(date('M j, Y, g:i A', strtotime($currentElection['end_date']))) ?></strong></p>
						</div>
					<?php else: ?>
						<div class="admin-empty-election"><span><i class="ti ti-calendar-off" aria-hidden="true"></i></span><div><strong>No elections yet</strong><p>Create an election to see its schedule and status here.</p></div></div>
					<?php endif; ?>
				</section>

				<p class="admin-dashboard-footnote"><i class="ti ti-info-circle" aria-hidden="true"></i> Counts include records from all elections.</p>
			</main>
		</div>
	</div>
</body>
</html>
