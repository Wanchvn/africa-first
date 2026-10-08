<?php
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session.php';
    start_secure_session();
}

require_once __DIR__ . '/../lang/init.php';
require_once __DIR__ . '/csrf.php';

$logged_in = isset($_SESSION['user_id']);

if ($logged_in) {
    require_once __DIR__ . '/../config/db.php';

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :me AND is_read = 0");
    $stmt->execute([':me' => $_SESSION['user_id']]);
    $unread_count = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $is_admin = (bool)$stmt->fetchColumn();
} else {
    $unread_count = 0;
    $is_admin = false;
}

if (!isset($page_title)) $page_title = 'Qarota';

$og_description = "Africa's social network. Your data stays home.";
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($_SESSION['lang']) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> — Qarota</title>

    <link rel="stylesheet" href="assets/css/style.css">

    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32.png">
    <link rel="icon" type="image/png" sizes="512x512" href="assets/img/favicon-512.png">
    <link rel="apple-touch-icon" href="assets/img/favicon-512.png">

    <!-- Social preview (Open Graph) -->
    <meta property="og:title" content="<?= htmlspecialchars($page_title) ?> — Qarota">
    <meta property="og:description" content="<?= htmlspecialchars($og_description) ?>">
    <meta property="og:image" content="https://qarota.com/assets/img/social-preview.png">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
</head>
<body>
<header class="site-header">
    <nav>
        <a href="feed.php" class="brand">
            <img src="assets/img/logo-icon.PNG" alt="Qarota" class="brand-icon">
            <span class="brand-text">Qarota</span>
        </a>

        <button class="nav-toggle" onclick="toggleNav()" aria-label="Menu">
            <span class="nav-toggle-icon">☰</span>
        </button>

        <?php if ($logged_in): ?>
            <form method="GET" action="search.php" class="nav-search">
                <input type="text" name="q"
                       placeholder="<?= __('search_placeholder') ?>"
                       autocomplete="off">
            </form>
        <?php endif; ?>

        <div class="nav-links" id="navLinks">
            <?php if ($logged_in): ?>
                <a href="feed.php" title="<?= __('nav_feed') ?>">
                    <span class="nav-icon">🏠</span>
                    <span class="nav-label"><?= __('nav_feed') ?></span>
                </a>
                <a href="notifications.php" title="<?= __('nav_notifications') ?>">
                    <span class="nav-icon">🔔</span>
                    <span class="nav-label"><?= __('nav_notifications') ?></span>
                    <?php if ($unread_count > 0): ?>
                        <span class="badge"><?= $unread_count ?></span>
                    <?php endif; ?>
                </a>
                <a href="discover_feed.php" title="Explore">
                    <span class="nav-icon">✨</span>
                    <span class="nav-label">Explore</span>
                </a>
                <a href="topics.php" title="Topics">
                    <span class="nav-icon">#️⃣</span>
                    <span class="nav-label">Topics</span>
                </a>
                <a href="discover.php" title="<?= __('nav_discover') ?>">
                    <span class="nav-icon">🧭</span>
                    <span class="nav-label"><?= __('nav_discover') ?></span>
                </a>
                <?php if ($is_admin): ?>
                    <a href="admin_reports.php" title="<?= __('nav_admin') ?>">
                        <span class="nav-icon">⚙️</span>
                        <span class="nav-label"><?= __('nav_admin') ?></span>
                    </a>
                <?php endif; ?>
                <a href="privacy.php" title="<?= __('nav_privacy') ?>">
                    <span class="nav-icon">🔒</span>
                    <span class="nav-label"><?= __('nav_privacy') ?></span>
                </a>
                <a href="moderation.php" title="<?= __('nav_moderation') ?>">
                    <span class="nav-icon">📖</span>
                    <span class="nav-label"><?= __('nav_moderation') ?></span>
                </a>
                <a href="profile.php" title="<?= __('nav_profile') ?>">
                    <span class="nav-icon">👤</span>
                    <span class="nav-label"><?= __('nav_profile') ?></span>
                </a>
                <a href="change_password.php" title="Change password">
                    <span class="nav-icon">🔑</span>
                    <span class="nav-label">Password</span>
                </a>
                <a href="logout.php" class="nav-logout" title="<?= __('nav_logout') ?>">
                    <span class="nav-icon">→</span>
                    <span class="nav-label"><?= __('nav_logout') ?></span>
                </a>
            <?php else: ?>
                <a href="login.php" class="nav-logout">
                    <span class="nav-label"><?= __('nav_login') ?></span>
                </a>
                <a href="register.php">
                    <span class="nav-label"><?= __('nav_register') ?></span>
                </a>
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