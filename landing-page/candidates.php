<?php
session_start();

function escape(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function candidatePhotoSource(?string $photo): ?string
{
	if ($photo === null || trim($photo) === '') {
		return null;
	}

	$photo = trim($photo);
	$scheme = parse_url($photo, PHP_URL_SCHEME);
	if ($scheme !== null) {
		return in_array(strtolower($scheme), ['http', 'https'], true) ? $photo : null;
	}

	if (str_starts_with($photo, '../') || (str_starts_with($photo, '/') && !str_starts_with($photo, '//'))) {
		return $photo;
	}

	return '../' . ltrim($photo, './');
}

$voterName = $_SESSION['voter_name'] ?? null;
$positions = [];
$candidatesByPosition = [];
$election = null;
$databaseError = false;
$conn = null;

try {
	require_once __DIR__ . '/../database/connect.php';
	require_once __DIR__ . '/../database/sync-election-statuses.php';
	syncElectionStatuses($conn);

	$electionQuery = $conn->query("SELECT election_id, election_name, status, start_date, end_date FROM elections WHERE status IN ('ongoing', 'scheduled') AND end_date > NOW() ORDER BY (status = 'ongoing') DESC, start_date ASC LIMIT 1");
	$election = $electionQuery->fetch_assoc();

	if ($election) {
		$electionId = (int) $election['election_id'];
		$positionQuery = $conn->prepare('SELECT position_id, position_name FROM positions WHERE election_id = ? ORDER BY position_order, position_name');
		$positionQuery->bind_param('i', $electionId);
		$positionQuery->execute();
		$positions = $positionQuery->get_result()->fetch_all(MYSQLI_ASSOC);
		$positionQuery->close();

		foreach ($positions as $position) {
			$candidatesByPosition[$position['position_name']] = [];
		}

		$candidateQuery = $conn->prepare("
			SELECT c.fullname, c.photo, c.student_id, c.year_level, c.course, p.position_name
			FROM candidates c
			INNER JOIN positions p ON p.position_id = c.position_id
			WHERE c.election_id = ? AND c.status = 'active'
			ORDER BY p.position_order, p.position_name, c.fullname
		");
		$candidateQuery->bind_param('i', $electionId);
		$candidateQuery->execute();
		$candidateResult = $candidateQuery->get_result();

		while ($candidate = $candidateResult->fetch_assoc()) {
			if (isset($candidatesByPosition[$candidate['position_name']])) {
				$candidatesByPosition[$candidate['position_name']][] = $candidate;
			}
		}

		$candidateQuery->close();
	}

	$conn->close();
} catch (mysqli_sql_exception $exception) {
	error_log('Candidate page database error: ' . $exception->getMessage());
	$databaseError = true;
	if ($conn instanceof mysqli) {
		$conn->close();
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="theme-color" content="#f4fbfa">
	<title>Meet the Candidates | CCS E-Voting System</title>
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
			<span class="brand-copy">
				<strong>CCS E-VOTING SYSTEM</strong>
				<small>COLLEGE OF COMPUTER STUDIES</small>
			</span>
		</a>

		<nav class="main-nav" aria-label="Main navigation">
			<a class="nav-link" href="index.php">Home</a>
			<a class="nav-link" href="index.php#about">About</a>
			<a class="nav-link is-active" href="candidates.php">Candidates</a>
			<a class="nav-link" href="results.php">Results</a>
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
				<a href="candidates.php" aria-current="page">Candidates</a>
				<a href="results.php">Results</a>
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
		<section class="candidates-intro" aria-labelledby="candidates-title">
			<p class="eyebrow">MEET YOUR REPRESENTATIVES</p>
			<h1 id="candidates-title">Get to know your candidates</h1>
			<p>Learn about the students running to represent the College of Computer Studies.</p>
			<?php if ($election): ?>
				<span class="candidates-election"><i class="ti ti-calendar-event" aria-hidden="true"></i> <?= escape($election['election_name']) ?> &middot; <?= escape(ucfirst($election['status'])) ?></span>
			<?php endif; ?>
		</section>

		<section class="candidates-panel" aria-label="Candidate profiles">
			<?php if ($databaseError): ?>
				<div class="candidates-empty" role="alert">
					<span class="candidates-empty-icon"><i class="ti ti-database-exclamation" aria-hidden="true"></i></span>
					<h2>Candidate information is unavailable</h2>
					<p>Please check that the voting system database is set up and try again.</p>
				</div>
			<?php elseif (!$election): ?>
				<div class="candidates-empty">
					<span class="candidates-empty-icon"><i class="ti ti-calendar-off" aria-hidden="true"></i></span>
					<h2>No current election</h2>
					<p>Candidate profiles will appear when an election is scheduled or ongoing.</p>
				</div>
			<?php else: ?>
				<div class="candidates-table-wrap">
					<table class="candidates-table">
						<thead>
							<tr><th scope="col">Position</th><th scope="col">Candidate profiles</th></tr>
						</thead>
						<tbody>
							<?php foreach ($positions as $position): ?>
								<tr>
									<th class="candidate-position" scope="row"><span><?= escape($position['position_name']) ?></span></th>
									<td>
										<?php if ($candidatesByPosition[$position['position_name']]): ?>
											<div class="candidate-grid">
												<?php foreach ($candidatesByPosition[$position['position_name']] as $candidate): ?>
													<?php $photoSource = candidatePhotoSource($candidate['photo']); ?>
													<article class="candidate-card">
														<?php if ($photoSource !== null): ?>
															<img class="candidate-photo" src="<?= escape($photoSource) ?>" alt="Profile of <?= escape($candidate['fullname']) ?>">
														<?php else: ?>
															<div class="candidate-photo candidate-photo-placeholder" aria-label="No profile picture available"><i class="ti ti-user" aria-hidden="true"></i></div>
														<?php endif; ?>
														<div class="candidate-info">
															<h2><?= escape($candidate['fullname']) ?></h2>
															<dl>
																<div><dt>Year level</dt><dd><?= escape($candidate['year_level'] ?: 'Not provided') ?></dd></div>
																<div><dt>Student ID</dt><dd><?= escape($candidate['student_id'] ?: 'Not provided') ?></dd></div>
																<div><dt>Course</dt><dd><?= escape($candidate['course'] ?: 'Not provided') ?></dd></div>
															</dl>
														</div>
													</article>
												<?php endforeach; ?>
											</div>
										<?php else: ?>
											<p class="position-empty">No candidates have been announced for this position yet.</p>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<div class="candidates-action">
					<?php if ($election['status'] === 'ongoing'): ?>
						<p>Ready to make your choice?</p>
						<?php if (($_SESSION['role'] ?? null) === 'voter'): ?>
							<a class="button button-primary candidates-vote-button" href="vote.php"><i class="ti ti-vote" aria-hidden="true"></i> Cast votes</a>
						<?php elseif (($_SESSION['role'] ?? null) === 'admin'): ?>
							<p>Administrator accounts cannot cast voter ballots.</p>
						<?php else: ?>
							<a class="button button-primary candidates-vote-button" href="log-in.php?next=vote.php"><i class="ti ti-vote" aria-hidden="true"></i> Log in to vote</a>
						<?php endif; ?>
					<?php else: ?>
						<p>Voting has not opened for this election.</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</section>
	</main>

	<footer class="site-footer">
		<p>CCS E-Voting System &copy; 2026 <span aria-hidden="true">|</span> College of Computer Studies</p>
		<div class="social-links" aria-label="Social links">
			<a href="#" aria-label="Facebook"><i class="ti ti-brand-facebook-filled" aria-hidden="true"></i></a>
			<a href="#" aria-label="Email"><i class="ti ti-mail-filled" aria-hidden="true"></i></a>
			<a href="#" aria-label="College home"><i class="ti ti-home-filled" aria-hidden="true"></i></a>
		</div>
	</footer>
</body>
</html>
