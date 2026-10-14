<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $boost = isset($_POST['feed_language_boost']) ? 1 : 0;
    $pdo->prepare("UPDATE users SET feed_language_boost = :v WHERE id = :id")
        ->execute([':v' => $boost, ':id' => $user_id]);
    $message = 'Feed preference saved.';
}

$stmt = $pdo->prepare("SELECT languages, feed_language_boost FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $user_id]);
$prefs = $stmt->fetch();

$page_title = 'Feed settings';
require 'includes/header.php';
?>

<div class="container" style="max-width: 640px;">
    <h1>Feed</h1>

    <?php if ($message): ?>
        <div class="message" style="border-left-color: var(--baobab-green); background: #E8F5E9;">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="card">
        <?= csrf_field() ?>
        <h2 style="margin-top:0;">Language priority</h2>
        <p style="color:var(--muted); font-size:0.9rem;">
            When enabled, posts in your languages appear first in your feed.
            Everything else still shows up — just below.
            No algorithm decides what you see; you do.
        </p>

        <label class="toggle-row" style="border-bottom:none;">
            <input type="checkbox" name="feed_language_boost"
                   <?= (int)$prefs['feed_language_boost'] === 1 ? 'checked' : '' ?>>
            <span class="toggle-label">
                <strong>Prioritize posts in my languages</strong>
                <span class="toggle-hint">
                    Your languages: <?= htmlspecialchars($prefs['languages'] ?: 'not set') ?>
                    · <a href="edit_profile.php">Edit</a>
                </span>
            </span>
        </label>

        <div style="display:flex; gap: var(--space-2); margin-top: var(--space-4);">
            <button type="submit">Save</button>
            <a href="feed.php" class="btn-secondary">Back to feed</a>
        </div>
    </form>
</div>

<?php require 'includes/footer.php'; ?>