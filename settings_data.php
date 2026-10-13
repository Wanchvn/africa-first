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

    $low_data = isset($_POST['low_data_mode']) ? 1 : 0;

    $stmt = $pdo->prepare("UPDATE users SET low_data_mode = :v WHERE id = :id");
    $stmt->execute([':v' => $low_data, ':id' => $user_id]);

    $message = 'Data preferences saved.';

    // Refresh session flag if you cache it
    $_SESSION['low_data_mode'] = $low_data;
}

// Load current value
$stmt = $pdo->prepare("SELECT low_data_mode FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $user_id]);
$low_data = (int)$stmt->fetchColumn() === 1;

$page_title = 'Data settings';
require 'includes/header.php';
?>

<div class="container" style="max-width:640px;">
    <h1>Data</h1>

    <?php if ($message): ?>
        <div class="message" style="border-left-color: var(--baobab-green); background: #E8F5E9;">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="card">
        <?= csrf_field() ?>

        <h2 style="margin-top:0;">Low-data mode</h2>
        <p style="color:var(--muted); font-size:0.9rem; margin-bottom: var(--space-4);">
            If you're on a metered connection, turn this on. Qarota will stop loading images
            and video previews automatically. You can tap any placeholder to load it once.
        </p>

        <label class="toggle-row" style="border-bottom:none;">
            <input type="checkbox" name="low_data_mode" <?= $low_data ? 'checked' : '' ?>>
            <span class="toggle-label">
                <strong>Enable low-data mode</strong>
                <span class="toggle-hint">
                    Saves roughly 60–90% of data per page for typical feeds.
                    Voice notes and text always work.
                </span>
            </span>
        </label>

        <div style="display:flex; gap: var(--space-2); margin-top: var(--space-4);">
            <button type="submit">Save preferences</button>
            <a href="feed.php" class="btn-secondary">Back to feed</a>
        </div>
    </form>

    <div class="card" style="background: #FFF9F5; border-left: 4px solid var(--baobab-green);">
        <h3 style="margin-top:0;">How it works</h3>
        <ul style="margin: var(--space-2) 0; padding-left: var(--space-5); line-height:1.7;">
            <li>Images in feeds and profiles show as <strong>tap-to-load placeholders</strong></li>
            <li>Video messages don't autoplay or preload</li>
            <li>Avatars still load — they're tiny (usually under 20 KB)</li>
            <li>Voice notes still stream normally (5 MB max)</li>
            <li>Nothing is deleted — you can turn this off any time</li>
        </ul>
        <p style="color:var(--muted); font-size:0.85rem; margin-bottom:0;">
            Your data stays in Ghana either way. This setting only affects how much of it
            gets downloaded to your device.
        </p>
    </div>
</div>

<?php require 'includes/footer.php'; ?>