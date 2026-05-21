<?php
require '/home/collab/data/lib/lib.php';
session_boot();

if (session_user()) {
    header('Location: index.php');
    exit;
}

$err = '';
$emailInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailInput = trim($_POST['email'] ?? '');
    $pw = (string) ($_POST['password'] ?? '');
    if (!valid_email($emailInput) || !user_password_verify($emailInput, $pw)) {
        $err = 'Invalid email or password.';
        usleep(750000); // mild brute-force throttle
    } else {
        session_login($emailInput);
        header('Location: index.php');
        exit;
    }
}

$siteName = (string) cfg_get('site_name', 'ProjectCollab');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in — <?= html_escape($siteName) ?></title>
<link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
<main class="auth-card">
<h1><?= html_escape($siteName) ?></h1>
<h2>Sign in</h2>
<?php if ($err): ?>
<div class="error"><?= html_escape($err) ?></div>
<?php endif; ?>
<form method="POST" autocomplete="on">
<label>Email
<input type="email" name="email" value="<?= html_escape($emailInput) ?>" required autofocus autocomplete="username">
</label>
<label>Password
<input type="password" name="password" required autocomplete="current-password">
</label>
<button type="submit">Sign in</button>
</form>
<p class="muted"><a href="forgot.php">Forgot password?</a></p>
</main>
</body>
</html>
