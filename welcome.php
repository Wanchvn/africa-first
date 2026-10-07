<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT username FROM users WHERE id = :id");
$stmt->execute([':id' => $user_id]);
$username = $stmt->fetchColumn();

$page_title = 'Welcome';
require 'includes/header.php';
?>

<div class="welcome-hero" style="padding: var(--space-8) var(--space-4);">
    <h1 class="welcome-title" style="font-size: 2.2rem;">
        Welcome, <?= htmlspecialchars($username) ?> 🎉
    </h1>
    <p class="welcome-subtitle">
        Your Qarota account is ready. Here are three things to do next.
    </p>
</div>

<div class="card">
    <h2 style="margin-top:0;">1. Add a profile picture</h2>
    <p style="color: var(--muted); margin-bottom: var(--space-3);">
        Users with photos get more engagement.
    </p>
    <a href="profile.php" class="btn-secondary">Go to profile</a>
</div>

<div class="card">
    <h2 style="margin-top:0;">2. Find people to follow</h2>
    <p style="color: var(--muted); margin-bottom: var(--space-3);">
        Your feed stays empty until you follow people.
    </p>
    <a href="discover.php">Discover people</a>
</div>

<div class="card">
    <h2 style="margin-top:0;">3. Write your first post</h2>
    <p style="color: var(--muted); margin-bottom: var(--space-3);">
        Say hello. Tell people what you're working on.
    </p>
    <a href="profile.php">Write a post</a>
</div>

<p style="text-align:center; margin-top: var(--space-5);">
    <a href="feed.php">Or go straight to your feed →</a>
</p>

<?php require 'includes/footer.php'; ?>