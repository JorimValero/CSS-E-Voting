<?php
session_start();

if (isset($_GET['logout'])) {
	$_SESSION = [];
	session_destroy();
	header('Location: index.php');
	exit;
}

$voterName = $_SESSION['voter_name'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="theme-color" content="#f4fbfa">
	<title>CCS E-Voting System | College of Computer Studies</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
	<link rel="stylesheet" href="../front_end/style.css">
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
			<a class="nav-link is-active" href="index.php">Home</a>
			<a class="nav-link" href="#about">About</a>
			<a class="nav-link" href="candidates.php">Candidates</a>
			<a class="nav-link" href="#results">Results</a>
		</nav>

		<div class="header-actions">
			<?php if ($voterName !== null): ?>
				<span class="signed-in-label">Hi, <?= htmlspecialchars($voterName, ENT_QUOTES, 'UTF-8') ?></span>
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
				<a href="#about">About</a>
				<a href="candidates.php">Candidates</a>
				<a href="#results">Results</a>
				<?php if ($voterName !== null): ?>
					<a href="index.php?logout=1">Log out</a>
				<?php else: ?>
					<a href="log-in.php">Login</a>
					<a href="sign-up.php">Register</a>
				<?php endif; ?>
			</nav>
		</details>
	</header>

	<main>
		<section class="hero" aria-labelledby="hero-title">
			<div class="hero-photo" role="img" aria-label="A bright university campus building surrounded by trees"></div>
			<div class="hero-wash"></div>
			<div class="hero-content">
				<p class="eyebrow">COLLEGE OF COMPUTER STUDIES</p>
				<h1 id="hero-title">Your Voice Matters<br><span>Vote for a Better CCS</span></h1>
				<p class="hero-description">The CCS E-Voting System helps our college community choose the next leaders. Safe, simple, and convenient voting &mdash; all in one place.</p>
				<div class="hero-buttons">
					<a class="button button-primary" href="log-in.php"><i class="ti ti-vote" aria-hidden="true"></i> Login to Vote <i class="ti ti-arrow-right" aria-hidden="true"></i></a>
					<a class="button button-secondary" href="candidates.php"><i class="ti ti-users-group" aria-hidden="true"></i> View Candidates</a>
				</div>
			</div>
			<div class="ballot-illustration" aria-hidden="true">
				<div class="ballot-card"><i class="ti ti-check"></i></div>
				<div class="ballot-box">
					<span class="ballot-slot"></span>
					<img src="../image/logo-css.jpg" alt="">
				</div>
			</div>
			<div class="hero-swoop" aria-hidden="true"></div>
		</section>

		<section class="benefits" aria-labelledby="benefits-title">
			<div class="section-heading">
				<p class="eyebrow">WHY USE OUR SYSTEM?</p>
				<h2 id="benefits-title">A Smarter Way to Vote</h2>
				<p>Our system is built to make the voting process easy, secure, and transparent<br class="desktop-break"> for every CCS student.</p>
			</div>
			<div class="benefit-grid">
				<article class="benefit-card">
					<span class="benefit-icon"><i class="ti ti-shield-lock" aria-hidden="true"></i></span>
					<h3>Secure</h3>
					<p>Your data and votes<br>are protected.</p>
				</article>
				<article class="benefit-card">
					<span class="benefit-icon"><i class="ti ti-bolt-filled" aria-hidden="true"></i></span>
					<h3>Easy to Use</h3>
					<p>Simple steps for<br>a smooth voting experience.</p>
				</article>
				<article class="benefit-card">
					<span class="benefit-icon"><i class="ti ti-users-group" aria-hidden="true"></i></span>
					<h3>Transparent</h3>
					<p>Fair and open election<br>process.</p>
				</article>
				<article class="benefit-card">
					<span class="benefit-icon"><i class="ti ti-devices" aria-hidden="true"></i></span>
					<h3>Accessible</h3>
					<p>Vote anytime,<br>anywhere.</p>
				</article>
			</div>
		</section>

		<section class="election-links" aria-label="Election information">
			<article id="candidates">
				<span class="info-icon"><i class="ti ti-users" aria-hidden="true"></i></span>
				<div><p class="eyebrow">MEET YOUR REPRESENTATIVES</p><h2>Candidate profiles</h2><p>Candidate information will be available when the election is announced.</p></div>
				<a class="round-link" href="candidates.php" aria-label="View candidates"><i class="ti ti-arrow-up-right" aria-hidden="true"></i></a>
			</article>
			<article id="results">
				<span class="info-icon"><i class="ti ti-chart-bar" aria-hidden="true"></i></span>
				<div><p class="eyebrow">ELECTION OUTCOME</p><h2>Results and updates</h2><p>Official results will be posted after voting closes.</p></div>
				<span class="results-status">UPDATES AFTER POLLS</span>
			</article>
		</section>

		<section class="about-details" id="about" aria-labelledby="about-title">
			<div class="about-details-inner">
				<header class="about-details-heading">
					<p class="eyebrow">CCS E-VOTING SYSTEM</p>
					<h2 id="about-title">Making Every Student Voice Count</h2>
					<p>The CCS E-Voting System is a digital voting platform designed for the College of Computer Studies. It provides students with a simple and convenient way to participate in college elections and choose their representatives.</p>
					<p>Instead of relying entirely on traditional paper-based voting, the system brings important election activities into one organized platform&mdash;from discovering candidates to casting votes and viewing official election results.</p>
				</header>

				<section class="about-details-block" aria-labelledby="about-actions-title">
					<div class="about-details-subheading">
						<p class="eyebrow">YOUR ELECTION, ALL IN ONE PLACE</p>
						<h3 id="about-actions-title">What Can You Do?</h3>
					</div>
					<div class="about-action-grid">
						<article class="about-action-card">
							<span class="about-action-icon"><i class="ti ti-users-group" aria-hidden="true"></i></span>
							<div><h4>Explore Candidates</h4><p>Learn about the students running for different positions before making your choice.</p></div>
						</article>
						<article class="about-action-card">
							<span class="about-action-icon"><i class="ti ti-vote" aria-hidden="true"></i></span>
							<div><h4>Cast Your Vote</h4><p>Submit your vote through a straightforward digital voting process.</p></div>
						</article>
						<article class="about-action-card">
							<span class="about-action-icon"><i class="ti ti-chart-bar" aria-hidden="true"></i></span>
							<div><h4>View Election Results</h4><p>Check the official election outcome once voting has officially closed.</p></div>
						</article>
					</div>
				</section>

				<section class="about-details-block about-process" aria-labelledby="about-process-title">
					<div class="about-details-subheading">
						<p class="eyebrow">FOUR SIMPLE STEPS</p>
						<h3 id="about-process-title">How It Works</h3>
					</div>
					<ol class="about-steps">
						<li><span class="about-step-number">01</span><div><h4>Register</h4><p>Create your student account and provide the required information.</p></div></li>
						<li><span class="about-step-number">02</span><div><h4>Explore</h4><p>View the available candidates and their information.</p></div></li>
						<li><span class="about-step-number">03</span><div><h4>Vote</h4><p>Choose your preferred candidates and submit your vote.</p></div></li>
						<li><span class="about-step-number">04</span><div><h4>Results</h4><p>After the election closes, official results are made available through the system.</p></div></li>
					</ol>
				</section>

				<footer class="about-community">
					<p class="eyebrow">BUILT FOR THE CCS COMMUNITY</p>
					<h3>More organized, accessible, and convenient.</h3>
					<p>The system aims to make student elections more organized, accessible, and convenient while encouraging students to take an active role in their college community.</p>
					<p class="about-community-motto">Your choice shapes your student community.</p>
					<strong class="about-community-brand">CCS E-VOTING SYSTEM</strong>
					<span class="about-community-tagline">Your Voice. Your Choice. Your College.</span>
				</footer>
			</div>
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
