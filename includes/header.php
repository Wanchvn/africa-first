<?php
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session.php';
    start_secure_session();
}

require_once __DIR__ . '/../lang/init.php';
require_once __DIR__ . '/csrf.php';

$logged_in = isset($_SESSION['user_id']);

$unread_count    = 0;
$unread_messages = 0;
$is_admin        = false;
$user_theme      = 'light';

if ($logged_in) {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/messages.php';

    // Notifications
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :me AND is_read = 0");
    $stmt->execute([':me' => $_SESSION['user_id']]);
    $unread_count = (int)$stmt->fetchColumn();

    // Messages
    $unread_messages = unread_message_count($pdo, (int)$_SESSION['user_id']);

    // User info
    $stmt = $pdo->prepare("SELECT is_admin, theme FROM users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user_row   = $stmt->fetch();
    $is_admin   = (bool)($user_row['is_admin'] ?? false);
    $user_theme = $user_row['theme'] ?? 'light';
}

if (!isset($page_title)) $page_title = 'Qarota';
$og_description = "Africa's social network. Your data stays home.";
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($_SESSION['lang'] ?? 'en') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> — Qarota</title>

    <link rel="stylesheet" href="assets/css/style.css">

    <!-- Icons -->
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32.png">
    <link rel="icon" type="image/png" sizes="512x512" href="assets/img/favicon-512.png">

    <!-- PWA -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#C65D3B">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Qarota">
    <link rel="apple-touch-icon" href="assets/img/icon-192.png">

    <!-- Open Graph -->
    <meta property="og:title" content="<?= htmlspecialchars($page_title) ?> — Qarota">
    <meta property="og:description" content="<?= htmlspecialchars($og_description) ?>">
    <meta property="og:image" content="https://qarota.com/assets/img/social-preview.png">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">

    <script src="assets/js/lucide.min.js"></script>
</head>
<body class="<?= $user_theme === 'dark' ? 'dark-mode' : '' ?>">
<header class="site-header">
    <nav>
        <a href="feed.php" class="brand">
            <img src="assets/img/logo-icon.PNG" alt="Qarota" class="brand-icon">
            <span class="brand-text">Qarota</span>
        </a>

        <button class="nav-toggle" onclick="toggleNav()" aria-label="Menu">
            <i data-lucide="menu"></i>
        </button>

        <?php if ($logged_in): ?>
            <form method="GET" action="search.php" class="nav-search">
                <i data-lucide="search" class="nav-search-icon"></i>
                <input type="text" name="q" placeholder="<?= __('search_placeholder') ?>" autocomplete="off">
            </form>
        <?php endif; ?>

        <div class="nav-links" id="navLinks">
            <?php if ($logged_in): ?>
                <a href="feed.php" title="<?= __('nav_feed') ?>">
                    <i data-lucide="home" class="nav-icon"></i>
                    <span class="nav-label"><?= __('nav_feed') ?></span>
                </a>
                <a href="notifications.php" title="<?= __('nav_notifications') ?>">
                    <i data-lucide="bell" class="nav-icon"></i>
                    <span class="nav-label"><?= __('nav_notifications') ?></span>
                    <?php if ($unread_count > 0): ?>
                        <span class="badge"><?= $unread_count ?></span>
                    <?php endif; ?>
                </a>
                <a href="messages.php" title="<?= __('messages_title') ?>">
                    <i data-lucide="mail" class="nav-icon"></i>
                    <span class="nav-label"><?= __('messages_title') ?></span>
                    <?php if ($unread_messages > 0): ?>
                        <span class="badge"><?= $unread_messages ?></span>
                    <?php endif; ?>
                </a>
                <a href="discover_feed.php" title="Explore">
                    <i data-lucide="sparkles" class="nav-icon"></i>
                    <span class="nav-label">Explore</span>
                </a>
                <a href="topics.php" title="Topics">
                    <i data-lucide="hash" class="nav-icon"></i>
                    <span class="nav-label">Topics</span>
                </a>
                <a href="discover.php" title="<?= __('nav_discover') ?>">
                    <i data-lucide="compass" class="nav-icon"></i>
                    <span class="nav-label"><?= __('nav_discover') ?></span>
                </a>
                <?php if ($is_admin): ?>
                    <a href="admin_reports.php" title="<?= __('nav_admin') ?>">
                        <i data-lucide="settings" class="nav-icon"></i>
                        <span class="nav-label"><?= __('nav_admin') ?></span>
                    </a>
                <?php endif; ?>
                <a href="privacy.php" title="<?= __('nav_privacy') ?>">
                    <i data-lucide="lock" class="nav-icon"></i>
                    <span class="nav-label"><?= __('nav_privacy') ?></span>
                </a>
                <a href="moderation.php" title="<?= __('nav_moderation') ?>">
                    <i data-lucide="book-open" class="nav-icon"></i>
                    <span class="nav-label"><?= __('nav_moderation') ?></span>
                </a>
                <a href="profile.php" title="<?= __('nav_profile') ?>">
                    <i data-lucide="user" class="nav-icon"></i>
                    <span class="nav-label"><?= __('nav_profile') ?></span>
                </a>
                <a href="saved.php" title="Saved posts">
                    <i data-lucide="bookmark" class="nav-icon"></i>
                    <span class="nav-label">Saved</span>
                </a>
                <a href="change_password.php" title="Change password">
                    <i data-lucide="key" class="nav-icon"></i>
                    <span class="nav-label">Password</span>
                </a>
                <a href="logout.php" class="nav-logout" title="<?= __('nav_logout') ?>">
                    <i data-lucide="log-out" class="nav-icon"></i>
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
            <a href="?setlang=en" class="<?= ($_SESSION['lang'] ?? 'en') === 'en' ? 'active' : '' ?>">EN</a>
            <a href="?setlang=tw" class="<?= ($_SESSION['lang'] ?? 'en') === 'tw' ? 'active' : '' ?>">TW</a>
            <a href="?setlang=dg" class="<?= ($_SESSION['lang'] ?? 'en') === 'dg' ? 'active' : '' ?>">DG</a>
            <a href="?setlang=fr" class="<?= ($_SESSION['lang'] ?? 'en') === 'fr' ? 'active' : '' ?>">FR</a>
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