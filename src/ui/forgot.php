<?php
require '/home/collab/data/lib/lib.php';
session_boot();

$msg = '';
$emailInput = '';
$siteName = (string) cfg_get('site_name', 'ProjectCollab');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailInput = trim($_POST['email'] ?? '');

    // Always show a generic success message to avoid email enumeration.
    $genericMsg = 'If that email is registered, a password reset link has been sent.';

    if (valid_email($emailInput)) {
        $u = user_get($emailInput);
        if ($u) {
            // Rate limit
            $rateMin = (int) cfg_get('rate_limit_reset_minutes', 5);
            $allow = true;
            if (!empty($u['reset_token_requested'])) {
                $lastTs = strtotime($u['reset_token_requested']);
                if ($lastTs && time() - $lastTs < $rateMin * 60) $allow = false;
            }
            if ($allow) {
                $token = gen_token();
                $ttl = (int) cfg_get('reset_token_ttl_minutes', 60);
                $u['reset_token']           = hash('sha256', $token);
                $u['reset_token_expires']   = gmdate('Y-m-d\TH:i:s\Z', time() + $ttl * 60);
                $u['reset_token_requested'] = now_iso();
                user_put($emailInput, $u);

                $base = rtrim((string) cfg_get('site_url', ''), '/');
                $url = $base . '/reset.php?token=' . urlencode($token) . '&email=' . urlencode($emailInput);
                $body = "Hello,\n\n"
                      . "A password reset was requested for your $siteName account.\n\n"
                      . "Click the link below to set a new password (valid for $ttl minutes):\n\n"
                      . "$url\n\n"
                      . "If you didn't request this, you can safely ignore this email.\n";
                send_mail($emailInput, "Password reset — $siteName", $body);
            }
        }
    }
    $msg = $genericMsg;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot password — <?= html_escape($siteName) ?></title>
<link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
<main class="auth-card">
<h1><?= html_escape($siteName) ?></h1>
<h2>Forgot password</h2>
<?php if ($msg): ?>
<div class="success"><?= html_escape($msg) ?></div>
<p class="muted"><a href="login.php">Back to sign in</a></p>
<?php else: ?>
<p>Enter your email and we'll send you a reset link.</p>
<form method="POST">
<label>Email
<input type="email" name="email" value="<?= html_escape($emailInput) ?>" required autofocus>
</label>
<button type="submit">Send reset link</button>
</form>
<p class="muted"><a href="login.php">Back to sign in</a></p>
<?php endif; ?>
</main>
</body>
</html>
