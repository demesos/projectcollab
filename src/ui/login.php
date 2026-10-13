<?php
require '/home/collab/data/lib/lib.php';
session_boot();

if (session_user()) {
    header('Location: index.php');
    exit;
}

$err = '';
$emailInput = '';
// Default to remembered (pre-checked); on POST, honour the checkbox
$remember = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailInput = trim($_POST['email'] ?? '');
    $pw = (string) ($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);
    if (!valid_email($emailInput) || !user_password_verify($emailInput, $pw)) {
        $err = 'Invalid email or password.';
        usleep(750000); // mild brute-force throttle
    } else {
        session_login($emailInput, $remember);
        // Fresh install convenience: the first admin login provisions the
        // shared guest-worker secret if it doesn't exist yet. Best-effort -
        // never block a login over it.
        $u = user_get($emailInput);
        if (($u['role'] ?? '') === 'admin') {
            try { guest_secret_ensure(); } catch (\Throwable $e) { /* next login retries */ }
        }
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
<label class="auth-remember">
<input type="checkbox" name="remember" value="1" <?= $remember ? 'checked' : '' ?>>
Keep me signed in
</label>
<button type="submit">Sign in</button>
</form>
<p class="muted"><a href="forgot.php">Forgot password?</a></p>
</main>
</body>
</html>
