<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$pageTitle = isset($pageTitle) ? $pageTitle : 'Baobab';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | Baobab</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; color: #203128; background: #f4f7f3; }
        * { box-sizing: border-box; }
        body { margin: 0; }
        a { color: #176b45; }
        .topbar { background: #174d35; color: #fff; }
        .nav { max-width: 960px; margin: auto; padding: 14px 20px; display: flex; align-items: center; gap: 18px; }
        .brand { color: #fff; font-size: 1.25rem; font-weight: 750; text-decoration: none; margin-right: auto; }
        .nav a:not(.brand) { color: #fff; text-decoration: none; }
        .nav form { margin: 0; }
        .nav button { color: #fff; background: transparent; border: 0; padding: 0; font: inherit; cursor: pointer; }
        main { width: min(100% - 32px, 720px); margin: 36px auto; }
        .card { background: #fff; border: 1px solid #e1e9e1; border-radius: 12px; padding: 24px; box-shadow: 0 5px 18px #1739230b; }
        h1, h2 { margin-top: 0; }
        label { display: block; font-weight: 600; margin: 16px 0 6px; }
        input, textarea { display: block; width: 100%; border: 1px solid #bdcbbf; border-radius: 7px; padding: 11px 12px; font: inherit; }
        textarea { min-height: 110px; resize: vertical; }
        button, .button { display: inline-block; border: 0; border-radius: 7px; background: #176b45; color: #fff; font: inherit; font-weight: 650; padding: 10px 16px; cursor: pointer; text-decoration: none; }
        .form-submit { margin-top: 18px; }
        .alert { border-radius: 7px; padding: 12px 14px; margin: 16px 0; }
        .error { color: #812d25; background: #fff0ed; }
        .success { color: #185436; background: #eaf7ed; }
        .muted { color: #64746a; }
        .post { border-top: 1px solid #e5ebe5; padding: 18px 0; }
        .post:first-of-type { margin-top: 10px; }
        .post-meta { font-size: .9rem; color: #64746a; margin-bottom: 8px; }
        .post-content { white-space: pre-wrap; overflow-wrap: anywhere; margin: 0; }
        .footer { max-width: 960px; margin: 48px auto 0; padding: 20px; color: #64746a; text-align: center; }
        @media (max-width: 560px) { .nav { gap: 12px; flex-wrap: wrap; } .brand { width: 100%; } main { margin-top: 22px; } .card { padding: 19px; } }
    </style>
</head>
<body>
<header class="topbar">
    <nav class="nav" aria-label="Main navigation">
        <a class="brand" href="index.php">Baobab</a>
        <?php if (!empty($_SESSION['usuario_id'])): ?>
            <a href="feed.php">Feed</a>
            <a href="perfil.php">My profile</a>
            <?php if (function_exists('csrf_token')): ?>
                <form action="logout.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <button type="submit">Log out</button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <a href="login.php">Log in</a>
            <a href="registro.php">Sign up</a>
        <?php endif; ?>
    </nav>
</header>
<main>
