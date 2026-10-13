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
$nav_user_avatar = null;
$nav_user_name   = '';
$user_low_data   = false;

if ($logged_in) {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/messages.php';

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :me AND is_read = 0");
    $stmt->execute([':me' => $_SESSION['user_id']]);
    $unread_count = (int)$stmt->fetchColumn();

    $unread_messages = unread_message_count($pdo, (int)$_SESSION['user_id']);

    $stmt = $pdo->prepare("SELECT is_admin, theme, avatar, display_name, username, low_data_mode FROM users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user_row   = $stmt->fetch();
    $is_admin   = (bool)($user_row['is_admin'] ?? false);
    $user_theme = $user_row['theme'] ?? 'light';
    $nav_user_avatar = $user_row['avatar'] ?? null;
    $nav_user_name   = $user_row['display_name'] ?? $user_row['username'] ?? '';
    $user_low_data   = (int)($user_row['low_data_mode'] ?? 0) === 1;
    $_SESSION['low_data_mode'] = $user_low_data ? 1 : 0;
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

    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">

    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32.png">
    <link rel="icon" type="image/png" sizes="512x512" href="assets/img/favicon-512.png">

    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#C65D3B">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Qarota">
    <link rel="apple-touch-icon" href="assets/img/icon-192.png">

    <meta property="og:title" content="<?= htmlspecialchars($page_title) ?> — Qarota">
    <meta property="og:description" content="<?= htmlspecialchars($og_description) ?>">
    <meta property="og:image" content="https://qarota.com/assets/img/social-preview.png">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">

    <script src="assets/js/lucide.min.js"></script>
    <script>window.QAROTA_LOW_DATA = <?= $user_low_data ? 'true' : 'false' ?>;</script>
</head>
<body class="<?= $user_theme === 'dark' ? 'dark-mode' : '' ?><?= $user_low_data ? ' low-data' : '' ?>">
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

        <?php if ($logged_in): ?>

            <div class="nav-links" id="navLinks">
                <a href="feed.php" class="nav-link" title="<?= __('nav_feed') ?>">
                    <i data-lucide="home" class="nav-icon"></i>
                    <span class="nav-label"><?= __('nav_feed') ?></span>
                </a>

                <a href="notifications.php" class="nav-link" title="<?= __('nav_notifications') ?>" id="notifNavLink">
                    <i data-lucide="bell" class="nav-icon"></i>
                    <span class="nav-label"><?= __('nav_notifications') ?></span>
                    <span class="badge" id="notifBadge" <?= $unread_count > 0 ? '' : 'hidden' ?>>
                        <?= $unread_count ?>
                    </span>
                </a>

                <a href="messages.php" class="nav-link" title="<?= __('messages_title') ?>" id="msgNavLink">
                    <i data-lucide="mail" class="nav-icon"></i>
                    <span class="nav-label"><?= __('messages_title') ?></span>
                    <span class="badge" id="msgBadge" <?= $unread_messages > 0 ? '' : 'hidden' ?>>
                        <?= $unread_messages ?>
                    </span>
                </a>

                <span class="nav-divider" aria-hidden="true"></span>

                <a href="discover_feed.php" class="nav-link" title="Explore">
                    <i data-lucide="sparkles" class="nav-icon"></i>
                    <span class="nav-label">Explore</span>
                </a>

                <a href="topics.php" class="nav-link" title="Topics">
                    <i data-lucide="hash" class="nav-icon"></i>
                    <span class="nav-label">Topics</span>
                </a>

                <a href="discover.php" class="nav-link" title="<?= __('nav_discover') ?>">
                    <i data-lucide="compass" class="nav-icon"></i>
                    <span class="nav-label"><?= __('nav_discover') ?></span>
                </a>
            </div>

            <div class="lang-switcher">
                <a href="?setlang=en" class="<?= ($_SESSION['lang'] ?? 'en') === 'en' ? 'active' : '' ?>">EN</a>
                <a href="?setlang=tw" class="<?= ($_SESSION['lang'] ?? 'en') === 'tw' ? 'active' : '' ?>">TW</a>
                <a href="?setlang=dg" class="<?= ($_SESSION['lang'] ?? 'en') === 'dg' ? 'active' : '' ?>">DG</a>
                <a href="?setlang=fr" class="<?= ($_SESSION['lang'] ?? 'en') === 'fr' ? 'active' : '' ?>">FR</a>
            </div>

            <div class="profile-menu">
                <button type="button" class="profile-menu-btn" id="profileMenuBtn"
                        aria-expanded="false" aria-haspopup="true"
                        title="<?= htmlspecialchars($nav_user_name) ?>">
                    <?php if (!empty($nav_user_avatar)): ?>
                        <img src="<?= htmlspecialchars($nav_user_avatar) ?>" alt="" class="profile-menu-avatar">
                    <?php else: ?>
                        <span class="profile-menu-avatar profile-menu-avatar-placeholder">
                            <?= htmlspecialchars(mb_strtoupper(mb_substr($nav_user_name, 0, 1))) ?>
                        </span>
                    <?php endif; ?>
                </button>

                <div class="profile-menu-dropdown" id="profileMenuDropdown" hidden>

                    <a href="profile.php" class="profile-menu-header">
                        <?php if (!empty($nav_user_avatar)): ?>
                            <img src="<?= htmlspecialchars($nav_user_avatar) ?>" alt="" class="profile-menu-avatar-lg">
                        <?php else: ?>
                            <span class="profile-menu-avatar-lg profile-menu-avatar-placeholder">
                                <?= htmlspecialchars(mb_strtoupper(mb_substr($nav_user_name, 0, 1))) ?>
                            </span>
                        <?php endif; ?>
                        <span class="profile-menu-header-text">
                            <strong><?= htmlspecialchars($nav_user_name) ?></strong>
                            <small>View your profile</small>
                        </span>
                    </a>

                    <div class="profile-menu-sep"></div>

                    <a href="profile.php" class="profile-menu-item">
                        <i data-lucide="user"></i>
                        <span><?= __('nav_profile') ?></span>
                    </a>

                    <button type="button" class="profile-menu-item profile-menu-toggle"
                            id="settingsToggle" aria-expanded="false">
                        <i data-lucide="settings"></i>
                        <span>Settings</span>
                        <i data-lucide="chevron-down" class="profile-menu-caret-icon"></i>
                    </button>

                    <div class="profile-menu-submenu" id="settingsSubmenu" hidden>
                        <a href="change_password.php" class="profile-menu-item">
                            <i data-lucide="key"></i>
                            <span>Password</span>
                        </a>

                        <a href="settings_notifications.php" class="profile-menu-item">
                            <i data-lucide="bell"></i>
                            <span>Notifications</span>
                        </a>

                        <a href="settings_data.php" class="profile-menu-item">
                            <i data-lucide="wifi-off"></i>
                            <span>Data</span>
                        </a>

                        <a href="saved.php" class="profile-menu-item">
                            <i data-lucide="bookmark"></i>
                            <span>Saved posts</span>
                        </a>

                        <a href="privacy.php" class="profile-menu-item">
                            <i data-lucide="lock"></i>
                            <span><?= __('nav_privacy') ?></span>
                        </a>
                    </div>

                    <?php if ($is_admin): ?>
                        <div class="profile-menu-sep"></div>

                        <div class="profile-menu-section-label">
                            <i data-lucide="shield"></i>
                            <span>Admin</span>
                        </div>

                        <a href="admin_appeals.php" class="profile-menu-item">
                            <i data-lucide="gavel"></i>
                            <span>Appeals</span>
                        </a>

                        <a href="admin_reports.php" class="profile-menu-item">
                            <i data-lucide="flag"></i>
                            <span>Reported content</span>
                        </a>

                        <a href="moderation.php" class="profile-menu-item">
                            <i data-lucide="book-open"></i>
                            <span><?= __('nav_moderation') ?></span>
                        </a>
                    <?php endif; ?>

                    <div class="profile-menu-sep"></div>

                    <form method="POST" action="logout.php" class="profile-menu-logout-form">
                        <?= csrf_field() ?>
                        <button type="submit" class="profile-menu-item profile-menu-logout">
                            <i data-lucide="log-out"></i>
                            <span><?= __('nav_logout') ?></span>
                        </button>
                    </form>
                </div>
            </div>

        <?php else: ?>

            <div class="nav-links">
                <a href="login.php" class="nav-link">
                    <span class="nav-label"><?= __('nav_login') ?></span>
                </a>
                <a href="register.php" class="nav-link">
                    <span class="nav-label"><?= __('nav_register') ?></span>
                </a>
            </div>

            <div class="lang-switcher">
                <a href="?setlang=en" class="<?= ($_SESSION['lang'] ?? 'en') === 'en' ? 'active' : '' ?>">EN</a>
                <a href="?setlang=tw" class="<?= ($_SESSION['lang'] ?? 'en') === 'tw' ? 'active' : '' ?>">TW</a>
                <a href="?setlang=dg" class="<?= ($_SESSION['lang'] ?? 'en') === 'dg' ? 'active' : '' ?>">DG</a>
                <a href="?setlang=fr" class="<?= ($_SESSION['lang'] ?? 'en') === 'fr' ? 'active' : '' ?>">FR</a>
            </div>

        <?php endif; ?>
    </nav>
</header>

<div id="navOverlay" onclick="toggleNav()"></div>

<script>
/* ---- Mobile hamburger menu ---- */
function toggleNav() {
    var nav = document.getElementById('navLinks');
    var overlay = document.getElementById('navOverlay');
    if (!nav || !overlay) return;
    var isOpen = nav.classList.toggle('open');
    overlay.classList.toggle('open', isOpen);
}

/* ---- Profile dropdown ---- */
document.addEventListener('DOMContentLoaded', function () {
    var btn         = document.getElementById('profileMenuBtn');
    var dropdown    = document.getElementById('profileMenuDropdown');
    var settingsBtn = document.getElementById('settingsToggle');
    var settingsSub = document.getElementById('settingsSubmenu');

    if (!btn || !dropdown) return;

    function closeAll() {
        dropdown.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
        if (settingsSub) settingsSub.hidden = true;
        if (settingsBtn) settingsBtn.setAttribute('aria-expanded', 'false');
    }

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var isOpen = !dropdown.hidden;
        if (isOpen) {
            closeAll();
        } else {
            dropdown.hidden = false;
            btn.setAttribute('aria-expanded', 'true');
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    });

    if (settingsBtn && settingsSub) {
        settingsBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = !settingsSub.hidden;
            settingsSub.hidden = isOpen;
            settingsBtn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        });
    }

    document.addEventListener('click', function (e) {
        if (!dropdown.contains(e.target) && !btn.contains(e.target)) {
            closeAll();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAll();
    });
});

/* ---- Mobile nav: close on link click ---- */
document.addEventListener('DOMContentLoaded', function() {
    var navLinks = document.getElementById('navLinks');
    if (!navLinks) return;
    navLinks.querySelectorAll('a').forEach(function(link) {
        link.addEventListener('click', function() {
            navLinks.classList.remove('open');
            var ov = document.getElementById('navOverlay');
            if (ov) ov.classList.remove('open');
        });
    });
});
</script>

<main class="container">
    <?php if (!empty($_SESSION['flash'])): ?>
        <div class="message"><?= htmlspecialchars($_SESSION['flash'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>