<?php
require '/home/collab/data/lib/lib.php';
session_boot();

$msg = '';
$err = '';
$tokenValid = false;
$email = trim((string) ($_GET['email'] ?? $_POST['email'] ?? ''));
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$siteName = (string) cfg_get('site_name', 'ProjectCollab');

// Validate token
if (valid_email($email)) {
    $u = user_get($email);
    if ($u && !empty($u['reset_token']) && !empty($u['reset_token_expires'])) {
        if (hash_equals((string) $u['reset_token'], hash('sha256', $token))) {
            $exp = strtotime((string) $u['reset_token_expires']);
            if ($exp && $exp > time()) {
                $tokenValid = true;
            } else {
                $err = 'This reset link has expired. Please request a new one.';
            }
        }
    }
}
if (!$tokenValid && !$err) {
    $err = 'Invalid or expired reset link.';
}

if ($tokenValid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw1 = (string) ($_POST['password'] ?? '');
    $pw2 = (string) ($_POST['password_confirm'] ?? '');
    if (strlen($pw1) < 8) {
        $err = 'Password must be at least 8 characters.';
    } elseif ($pw1 !== $pw2) {
        $err = 'Passwords do not match.';
    } else {
        $u['password_hash']         = user_password_hash($pw1);
        $u['reset_token']           = null;
        $u['reset_token_expires']   = null;
        $u['reset_token_requested'] = null;
        user_put($email, $u);
        $msg = 'Your password has been reset. You can now sign in.';
        $tokenValid = false; // hide form
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset password — <?= html_escape($siteName) ?></title>
<link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
<main class="auth-card">
<h1><?= html_escape($siteName) ?></h1>
<h2>Reset password</h2>
<?php if ($msg): ?>
<div class="success"><?= html_escape($msg) ?></div>
<p><a href="login.php">Sign in</a></p>
<?php elseif ($err && !$tokenValid): ?>
<div class="error"><?= html_escape($err) ?></div>
<p class="muted"><a href="forgot.php">Request a new reset link</a></p>
<?php else: ?>
<?php if ($err): ?><div class="error"><?= html_escape($err) ?></div><?php endif; ?>
<p>Setting password for <strong><?= html_escape($email) ?></strong>.</p>
<form method="POST">
<input type="hidden" name="email" value="<?= html_escape($email) ?>">
<input type="hidden" name="token" value="<?= html_escape($token) ?>">
<label>New password
<input type="password" name="password" required autofocus minlength="8" autocomplete="new-password">
</label>
<label>Confirm new password
<input type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
</label>
<button type="submit">Set password</button>
</form>
<?php endif; ?>
</main>
</body>
</html>
