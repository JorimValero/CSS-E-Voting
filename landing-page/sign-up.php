<?php
session_start();

if (!isset($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$form = [
	'student_id' => '',
	'fullname' => '',
	'email' => '',
	'course' => '',
	'year_level' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	foreach ($form as $field => $value) {
		$postedValue = $_POST[$field] ?? '';
		$form[$field] = is_string($postedValue) ? trim($postedValue) : '';
	}

	$password = $_POST['password'] ?? '';
	$confirmPassword = $_POST['confirm_password'] ?? '';
	$csrfToken = $_POST['csrf_token'] ?? '';

	if (!is_string($csrfToken) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
		$error = 'Your session expired. Reload the page and try again.';
	} elseif (in_array('', [$form['student_id'], $form['fullname'], $form['email'], $form['course'], $form['year_level']], true)) {
		$error = 'Complete all required fields.';
	} elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
		$error = 'Enter a valid email address.';
	} elseif (strlen($form['student_id']) > 30 || strlen($form['fullname']) > 100 || strlen($form['email']) > 100 || strlen($form['course']) > 100 || strlen($form['year_level']) > 20) {
		$error = 'One or more fields exceed the allowed length.';
	} elseif (!is_string($password) || strlen($password) < 8 || strlen($password) > 72) {
		$error = 'Use a password between 8 and 72 characters.';
	} elseif (!is_string($confirmPassword) || $password !== $confirmPassword) {
		$error = 'The password confirmation does not match.';
	} else {
		require_once __DIR__ . '/../database/connect.php';
		$passwordHash = password_hash($password, PASSWORD_DEFAULT);
		$username = $form['student_id'];

		try {
			$stmt = $conn->prepare("INSERT INTO accounts (student_id, fullname, username, email, password, course, year_level, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'voter', 'active')");
			$stmt->bind_param('sssssss', $form['student_id'], $form['fullname'], $username, $form['email'], $passwordHash, $form['course'], $form['year_level']);
			$stmt->execute();
			$stmt->close();
			$conn->close();
			header('Location: log-in.php?registered=1');
			exit;
		} catch (mysqli_sql_exception $exception) {
			if (isset($stmt)) {
				$stmt->close();
			}
			$conn->close();
			if ($exception->getCode() === 1062) {
				$error = 'That student ID or email is already registered.';
			} else {
				error_log($exception->getMessage());
				$error = 'We could not create your account right now. Please try again.';
			}
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
	<title>Voter Sign Up | CCS E-Voting System</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
	<link rel="stylesheet" href="../front_end/style.css">
</head>
<body class="auth-page">
	<header class="site-header auth-header">
		<a class="brand" href="index.php" aria-label="CCS E-Voting System home">
			<img class="brand-seal" src="../image/logo-css.jpg" alt="College of Computer Studies seal">
			<span class="brand-copy"><strong>CCS E-VOTING SYSTEM</strong><small>COLLEGE OF COMPUTER STUDIES</small></span>
		</a>
		<a class="auth-back" href="index.php"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back to home</a>
	</header>

	<main class="auth-layout signup-layout">
		<aside class="auth-aside">
			<p class="eyebrow">COLLEGE OF COMPUTER STUDIES</p>
			<h1>Be part of<br>the decision.</h1>
			<p>Register with your student information to take part in CCS elections.</p>
			<div class="auth-aside-mark"><img src="../image/logo-css.jpg" alt=""><span>CCS<br>E-VOTING</span></div>
		</aside>
		<section class="auth-panel" aria-labelledby="signup-title">
			<div class="auth-heading">
				<p class="eyebrow">VOTER REGISTRATION</p>
				<h2 id="signup-title">Create your account</h2>
				<p>Enter your student details to get started.</p>
			</div>
			<?php if ($error !== ''): ?><p class="auth-notice auth-error" role="alert"><?= escape($error) ?></p><?php endif; ?>
			<form class="auth-form auth-grid" method="post" action="sign-up.php">
				<input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
				<div class="auth-field auth-field-wide">
					<label for="fullname">Full name <span>*</span></label>
					<input id="fullname" name="fullname" type="text" maxlength="100" value="<?= escape($form['fullname']) ?>" autocomplete="name" required>
				</div>
				<div class="auth-field">
					<label for="student_id">Student ID <span>*</span></label>
					<input id="student_id" name="student_id" type="text" maxlength="30" value="<?= escape($form['student_id']) ?>" autocomplete="username" required>
				</div>
				<div class="auth-field">
					<label for="email">Email <span>*</span></label>
					<input id="email" name="email" type="email" maxlength="100" value="<?= escape($form['email']) ?>" autocomplete="email" required>
				</div>
				<div class="auth-field">
					<label for="course">Course / program <span>*</span></label>
					<input id="course" name="course" type="text" maxlength="100" value="<?= escape($form['course']) ?>" required>
				</div>
				<div class="auth-field">
					<label for="year_level">Year level <span>*</span></label>
					<input id="year_level" name="year_level" type="text" maxlength="20" placeholder="e.g. 2nd year" value="<?= escape($form['year_level']) ?>" required>
				</div>
				<div class="auth-field">
					<label for="password">Password <span>*</span></label>
					<input id="password" name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required>
				</div>
				<div class="auth-field auth-field-wide">
					<label for="confirm_password">Confirm password <span>*</span></label>
					<input id="confirm_password" name="confirm_password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required>
				</div>
				<button class="auth-submit auth-field-wide" type="submit">Create voter account <i class="ti ti-arrow-right" aria-hidden="true"></i></button>
			</form>
			<p class="auth-switch">Already registered? <a href="log-in.php">Log in</a></p>
			<p class="auth-footnote">Student ID and email must each be unique. Passwords are stored securely.</p>
		</section>
	</main>
</body>
</html>
