<?php
require '/home/collab/data/lib/lib.php';
$user = require_login();
$siteName = (string) cfg_get('site_name', 'ProjectCollab');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= html_escape($siteName) ?></title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="style.css">
</head>
<body>
<header>
    <div class="header-inner">
        <a href="#" id="brand"><?= html_escape($siteName) ?></a>
        <a href="#" id="about-link" onclick="showAbout(); return false;">About</a>
        <nav id="breadcrumb"></nav>
        <div class="topbar-user">
            <span class="user-info">
                <?= html_escape($user['email']) ?>
                <span class="muted">(<?= html_escape($user['role']) ?>)</span>
            </span>
            <a href="logout.php" class="logout-link">Log out</a>
            <button id="theme-toggle" title="Toggle theme">◐</button>
        </div>
    </div>
</header>

<main id="app">
    <div class="loading">Loading…</div>
</main>

<footer>
    <span id="status"></span>
</footer>

<script>
window.CURRENT_USER = <?= json_encode([
    'email' => $user['email'],
    'role'  => $user['role'],
], JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="app.js"></script>
</body>
</html>
