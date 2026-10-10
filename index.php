<?php
/**
 * Qarota — Landing page.
 * Shown to non-logged-in visitors. Logged-in users are redirected to the feed.
 */
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';

// If already logged in, send to feed
if (isset($_SESSION['user_id'])) {
    header('Location: feed.php');
    exit;
}

$page_title = 'Qarota — Africa\'s social network';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($_SESSION['lang'] ?? 'en') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="description" content="A social network built for Africa. Your data stays home, your voice can't be silenced, and every moderation action is public.">

    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/landing.css">

    <!-- Icons -->
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32.png">
    <link rel="apple-touch-icon" href="assets/img/icon-192.png">

    <!-- PWA -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#C65D3B">

    <!-- OG -->
    <meta property="og:title" content="Qarota — Africa's social network">
    <meta property="og:description" content="Your data stays home. Your voice can't be silenced. Every moderation action is public.">
    <meta property="og:type" content="website">

    <script src="assets/js/lucide.min.js"></script>
</head>
<body class="landing-body">

<!-- ============================================================
     HEADER
     ============================================================ -->
<header class="landing-header">
    <div class="landing-header-inner">
        <a href="index.php" class="landing-brand">
            <img src="assets/img/logo-icon.PNG" alt="Qarota" class="brand-icon">
            <span class="brand-text">Qarota</span>
        </a>

        <div class="landing-lang">
            <a href="?setlang=en" class="<?= ($_SESSION['lang'] ?? 'en') === 'en' ? 'active' : '' ?>">EN</a>
            <a href="?setlang=tw" class="<?= ($_SESSION['lang'] ?? 'en') === 'tw' ? 'active' : '' ?>">TW</a>
            <a href="?setlang=dg" class="<?= ($_SESSION['lang'] ?? 'en') === 'dg' ? 'active' : '' ?>">DG</a>
            <a href="?setlang=fr" class="<?= ($_SESSION['lang'] ?? 'en') === 'fr' ? 'active' : '' ?>">FR</a>
        </div>

        <div class="landing-cta">
            <a href="login.php" class="landing-btn-ghost">Log in</a>
            <a href="register.php" class="landing-btn-primary">Sign up</a>
        </div>
    </div>
</header>

<!-- ============================================================
     HERO
     ============================================================ -->
<section class="hero">
    <div class="hero-inner">
        <h1 class="hero-title">
            A social network built for Africa.
        </h1>
        <p class="hero-subtitle">
            Your data stays home. Your voice can't be silenced.
            Every moderation action is public.
        </p>

        <div class="hero-actions">
            <a href="register.php" class="landing-btn-primary landing-btn-lg">
                Create your account
            </a>
            <a href="#why" class="landing-btn-ghost landing-btn-lg">
                Learn more
            </a>
        </div>

        <div class="hero-pillars">
            <div class="hero-pillar">
                <div class="hero-icon">🏠</div>
                <div class="hero-label">Data stays in Ghana</div>
            </div>
            <div class="hero-pillar">
                <div class="hero-icon">📢</div>
                <div class="hero-label">No silent bans</div>
            </div>
            <div class="hero-pillar">
                <div class="hero-icon">📖</div>
                <div class="hero-label">Public moderation log</div>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================
     WHY QAROTA — comparison
     ============================================================ -->
<section class="why" id="why">
    <div class="section-inner">
        <h2 class="section-title">Why Qarota?</h2>
        <p class="section-subtitle">
            Most social networks were built in California, for California.
            Qarota is built in Ghana, for Africa — and it shows.
        </p>

        <div class="compare">
            <div class="compare-col compare-them">
                <div class="compare-label">Most platforms</div>
                <ul>
                    <li>Your data sits on servers you'll never see</li>
                    <li>Posts disappear without explanation</li>
                    <li>Moderation happens in secret</li>
                    <li>Algorithms decide who you see</li>
                    <li>Your language is an afterthought</li>
                </ul>
            </div>

            <div class="compare-col compare-us">
                <div class="compare-label">Qarota</div>
                <ul>
                    <li>Your data stays in Ghana — you can export all of it</li>
                    <li>Every removal is logged publicly, with a reason</li>
                    <li>You can see every action admins take</li>
                    <li>You decide who to follow — no ranking, no algorithm</li>
                    <li>Built for Twi, Dagbani, English, French — from day one</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================
     FEATURES
     ============================================================ -->
<section class="features">
    <div class="section-inner">
        <h2 class="section-title">What you can do</h2>

        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">📝</div>
                <h3>Share posts</h3>
                <p>Text and photos. No character limit games. No trending drama.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">💬</div>
                <h3>Real conversations</h3>
                <p>Direct messages with text, voice notes, and images. Typing indicators, read receipts, delete, block. Everything you'd expect.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">🎙</div>
                <h3>Voice notes</h3>
                <p>Because not everyone types their language easily. Speak in Twi, Dagbani, Hausa — your voice, your words.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">🏘</div>
                <h3>Topics</h3>
                <p>Join communities around what you care about. No paid promotion — just people who share your interests.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">🛡</div>
                <h3>Real safety</h3>
                <p>Report anything — posts, comments, messages. Block anyone. Every action is transparent, and every block actually works.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">📖</div>
                <h3>Public moderation log</h3>
                <p>Anyone can see what was removed and why. You can read every decision an admin has ever made.</p>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================
     MISSION
     ============================================================ -->
<section class="mission">
    <div class="section-inner">
        <h2 class="section-title">Why we built this</h2>
        <p class="mission-text">
            Africa has over a billion people and almost no social network of its own.
            Every conversation, every photo, every voice note travels through servers
            we don't control, governed by laws we didn't write.
        </p>
        <p class="mission-text">
            Qarota is a small attempt to change that. Your data stays on servers in Ghana.
            Your posts are yours to take with you. Your language is treated as first-class.
            And every decision made about your content is public, permanent, and reasoned.
        </p>
        <p class="mission-text mission-emphasis">
            We're not trying to beat anyone. We're trying to build something that belongs to us.
        </p>
    </div>
</section>

<!-- ============================================================
     FINAL CTA
     ============================================================ -->
<section class="final-cta">
    <div class="section-inner">
        <h2>Ready to join?</h2>
        <p>Qarota is free. It always will be.</p>
        <a href="register.php" class="landing-btn-primary landing-btn-lg">
            Create your account
        </a>
        <p class="final-cta-small">
            Already have an account? <a href="login.php">Log in</a>
        </p>
    </div>
</section>

<!-- ============================================================
     FOOTER
     ============================================================ -->
<footer class="landing-footer">
    <div class="landing-footer-inner">
        <div class="landing-footer-brand">
            <img src="assets/img/logo-icon.PNG" alt="Qarota" class="brand-icon">
            <span class="brand-text">Qarota</span>
        </div>

        <div class="landing-footer-links">
            <a href="privacy.php">Privacy</a>
            <a href="moderation.php">Moderation log</a>
            <a href="terms.php">Terms</a>
            <a href="about.php">About</a>
        </div>

        <div class="landing-footer-note">
            Made in Ghana. Data stays in Ghana.
        </div>
    </div>
</footer>

<script>
// Init lucide icons
if (typeof lucide !== 'undefined') {
    lucide.createIcons();
}
</script>

<!-- PWA: register service worker -->
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('sw.js').catch(function () {});
    });
}
</script>

</body>
</html>