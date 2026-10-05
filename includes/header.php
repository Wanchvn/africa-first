<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../lang/init.php';

$logged_in = isset($_SESSION['user_id']);

if ($logged_in) {
    require_once __DIR__ . '/../config/db.php';
}

$unread_count = 0;
if ($logged_in) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :me AND is_read = 0");
    $stmt->execute([':me' => $_SESSION['user_id']]);
    $unread_count = (int)$stmt->fetchColumn();
}

$is_admin = false;
if ($logged_in) {
    $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $is_admin = (bool)$stmt->fetchColumn();
}

if (!isset($page_title)) $page_title = 'Qarota';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($_SESSION['lang']) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> — Qarota</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="site-header">
    <nav>
        <a href="feed.php" class="brand">Qarota</a>

        <button class="nav-toggle" onclick="toggleNav()" aria-label="Menu">
            <span class="nav-toggle-icon">☰</span>
        </button>

        <div class="nav-links" id="navLinks">
            <?php if ($logged_in): ?>
                <a href="feed.php"><?= __('nav_feed') ?></a>
                <a href="notificaciones.php" class="nav-notifications">
                    <?= __('nav_notifications') ?>
                    <?php if ($unread_count > 0): ?>
                        <span class="badge"><?= $unread_count ?></span>
                    <?php endif; ?>
                </a>
                <a href="descubrir.php"><?= __('nav_discover') ?></a>
                <a href="buscar.php"><?= __('nav_search') ?></a>
                <?php if ($is_admin): ?>
                    <a href="admin_reportes.php"><?= __('nav_admin') ?></a>
                <?php endif; ?>
                <a href="privacidad.php"><?= __('nav_privacy') ?></a>
                <a href="moderacion.php"><?= __('nav_moderation') ?></a>
                <a href="perfil.php"><?= __('nav_profile') ?></a>
                <a href="logout.php" class="nav-logout"><?= __('nav_logout') ?></a>
            <?php else: ?>
                <a href="login.php" class="nav-logout"><?= __('nav_login') ?></a>
                <a href="registro.php"><?= __('nav_register') ?></a>
            <?php endif; ?>
        </div>

        <div class="lang-switcher">
            <a href="?setlang=en" class="<?= $_SESSION['lang'] === 'en' ? 'active' : '' ?>">EN</a>
            <a href="?setlang=tw" class="<?= $_SESSION['lang'] === 'tw' ? 'active' : '' ?>">TW</a>
            <a href="?setlang=dg" class="<?= $_SESSION['lang'] === 'dg' ? 'active' : '' ?>">DG</a>
            <a href="?setlang=fr" class="<?= $_SESSION['lang'] === 'fr' ? 'active' : '' ?>">FR</a>
        </div>
    </nav>
</header>

<div id="navOverlay" onclick="toggleNav()"></div>

<script>
function toggleNav() {
    var nav = document.getElementById('navLinks');
    var overlay = document.getElementById('navOverlay');
    var isOpen = nav.classList.toggle('open');
    overlay.classList.toggle('open', isOpen);
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('#navLinks a').forEach(function(link) {
        link.addEventListener('click', function() {
            document.getElementById('navLinks').classList.remove('open');
            document.getElementById('navOverlay').classList.remove('open');
        });
    });
});
</script>

<main class="container">