<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();
require 'lang/init.php';
$page_title = 'Offline';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($_SESSION['lang'] ?? 'en') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offline — Qarota</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <meta name="theme-color" content="#C65D3B">
</head>
<body>
    <main class="container" style="max-width: 480px; text-align: center; padding: 64px 24px;">
        <div style="font-size: 64px; margin-bottom: 24px;">📡</div>
        <h1 style="font-size: 1.5rem;">You're offline</h1>
        <p style="color: var(--muted); margin: 16px 0 32px; line-height: 1.6;">
            Qarota needs an internet connection to load new posts and messages.
            Check your connection and try again.
        </p>
        <button onclick="location.reload()" style="width: 100%;">
            Try again
        </button>
    </main>
</body>
</html>