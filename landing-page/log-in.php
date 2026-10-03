<?php
session_start();

if (($_SESSION['role'] ?? null) === 'admin' && isset($_SESSION['account_id'])) {
	header('Location: ../admin/admin-landing-page.php');
	exit;
}

if (!isset($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$identifier = '';
$loginSuccess = false;
$nextPage = $_GET['next'] ?? '';
if (!is_string($nextPage) || $nextPage !== 'vote.php') {
	$nextPage = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$postedIdentifier = $_POST['identifier'] ?? '';
	$identifier = is_string($postedIdentifier) ? trim($postedIdentifier) : '';
	$password = $_POST['password'] ?? '';
	$csrfToken = $_POST['csrf_token'] ?? '';
	$postedNextPage = $_POST['next'] ?? '';
	$nextPage = is_string($postedNextPage) && $postedNextPage === 'vote.php' ? $postedNextPage : '';

	if (!is_string($csrfToken) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
		$error = 'Your session expired. Reload the page and try again.';
	} elseif ($identifier === '' || !is_string($password) || $password === '') {
		$error = 'Enter your student ID or email and password.';
	} elseif (strlen($identifier) > 100) {
		$error = 'Enter a valid student ID, username, or email address.';
	} else {
		require_once __DIR__ . '/../database/connect.php';
		$stmt = $conn->prepare("SELECT account_id, fullname, password, role FROM accounts WHERE (student_id = ? OR username = ? OR email = ?) AND status = 'active' LIMIT 1");
		$stmt->bind_param('sss', $identifier, $identifier, $identifier);
		$stmt->execute();
		$account = $stmt->get_result()->fetch_assoc();
		$stmt->close();
		$conn->close();

		if ($account && password_verify($password, $account['password'])) {
			session_regenerate_id(true);
			$_SESSION['account_id'] = (int) $account['account_id'];
			$_SESSION['account_name'] = $account['fullname'];
			$_SESSION['role'] = $account['role'];

			if ($account['role'] === 'admin') {
				unset($_SESSION['voter_id'], $_SESSION['voter_name'], $_SESSION['voter_role']);
				header('Location: ../admin/admin-landing-page.php');
				exit;
			}

			$_SESSION['voter_id'] = (int) $account['account_id'];
			$_SESSION['voter_name'] = $account['fullname'];
			$_SESSION['voter_role'] = 'voter';
			if ($nextPage === 'vote.php') {
				header('Location: vote.php');
				exit;
			}
			$loginSuccess = true;
		}

		if (!$loginSuccess) {
			$error = 'We could not verify those details. Check them and try again.';
		}
	}
}

function escape(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="theme-color" content="#f4fbfa">
	<title>Voter Login | CCS E-Voting System</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
	<link rel="stylesheet" href="../front_end/style.css?v=3">
</head>
<body class="auth-page">
	<header class="site-header auth-header">
		<a class="brand" href="index.php" aria-label="CCS E-Voting System home">
			<img class="brand-seal" src="../image/logo-css.jpg" alt="College of Computer Studies seal">
			<span class="brand-copy"><strong>CCS E-VOTING SYSTEM</strong><small>COLLEGE OF COMPUTER STUDIES</small></span>
		</a>
		<a class="auth-back" href="index.php"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back to home</a>
	</header>

	<main class="auth-layout">
		<aside class="auth-aside">
			<p class="eyebrow">YOUR CCS ELECTION</p>
			<h1>Your voice<br>starts here.</h1>
			<p>Sign in with your student ID or registered email to continue.</p>
			<div class="auth-aside-mark"><img src="../image/logo-css.jpg" alt=""><span>CCS<br>E-VOTING</span></div>
		</aside>
		<section class="auth-panel login-panel" aria-labelledby="login-title">
			<div class="auth-heading">
				<p class="eyebrow">VOTER ACCESS</p>
				<h2 id="login-title"><?= $loginSuccess ? 'You are signed in' : 'Welcome back' ?></h2>
				<p><?= $loginSuccess ? 'Your voter account is ready.' : 'Use your student ID, username, or email to sign in.' ?></p>
			</div>
			<?php if (isset($_GET['registered'])): ?><p class="auth-notice auth-success" role="status">Account created. You can log in now.</p><?php endif; ?>
			<?php if ($error !== ''): ?><p class="auth-notice auth-error" role="alert"><?= escape($error) ?></p><?php endif; ?>
			<?php if ($loginSuccess): ?>
				<div class="signed-in-panel">
					<span class="signed-in-icon"><i class="ti ti-check" aria-hidden="true"></i></span>
					<p>Signed in as <strong><?= escape($_SESSION['voter_name']) ?></strong>.</p>
					<a class="auth-submit" href="index.php">Continue to home <i class="ti ti-arrow-right" aria-hidden="true"></i></a>
				</div>
			<?php else: ?>
				<form class="auth-form" method="post" action="log-in.php">
					<input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
					<?php if ($nextPage === 'vote.php'): ?><input type="hidden" name="next" value="vote.php"><?php endif; ?>
					<div class="auth-field">
						<label for="identifier">Student ID, username, or email</label>
						<input id="identifier" name="identifier" type="text" value="<?= escape($identifier) ?>" autocomplete="username" required>
					</div>
					<div class="auth-field">
						<label for="password">Password</label>
						<input id="password" name="password" type="password" autocomplete="current-password" required>
					</div>
					<button class="auth-submit" type="submit">Log in <i class="ti ti-arrow-right" aria-hidden="true"></i></button>
				</form>
				<p class="auth-switch">New voter? <a href="sign-up.php">Create an account</a></p>
			<?php endif; ?>
			<p class="auth-footnote">Your account role determines which page opens after login.</p>
		</section>
	</main>
</body>
</html>
