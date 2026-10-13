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
$error   = '';

// ---- Handle save ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $notify_likes       = isset($_POST['notify_likes'])       ? 1 : 0;
    $notify_comments    = isset($_POST['notify_comments'])    ? 1 : 0;
    $notify_follows     = isset($_POST['notify_follows'])     ? 1 : 0;
    $notify_topic_posts = isset($_POST['notify_topic_posts']) ? 1 : 0;
    $notify_paused      = isset($_POST['notify_paused'])      ? 1 : 0;

    $stmt = $pdo->prepare("
        UPDATE users
        SET notify_likes       = :likes,
            notify_comments    = :comments,
            notify_follows     = :follows,
            notify_topic_posts = :topic_posts,
            notify_paused      = :paused
        WHERE id = :id
    ");
    $stmt->execute([
        ':likes'       => $notify_likes,
        ':comments'    => $notify_comments,
        ':follows'     => $notify_follows,
        ':topic_posts' => $notify_topic_posts,
        ':paused'      => $notify_paused,
        ':id'          => $user_id,
    ]);

    $message = 'Notification preferences saved.';
}

// ---- Load current values ----
$stmt = $pdo->prepare("
    SELECT notify_likes, notify_comments, notify_follows,
           notify_topic_posts, notify_paused
    FROM users WHERE id = :id LIMIT 1
");
$stmt->execute([':id' => $user_id]);
$prefs = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$prefs) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$page_title = 'Notification settings';
require 'includes/header.php';
?>

<div class="container" style="max-width:640px;">
    <h1>Notifications</h1>

    <?php if ($message): ?>
        <div class="message" style="border-left-color: var(--baobab-green); background: #E8F5E9;">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="message" style="border-left-color: var(--danger); background: #FDECEA;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="card">
        <?= csrf_field() ?>

        <h2 style="margin-top:0;">What to notify you about</h2>
        <p style="color:var(--muted); font-size:0.9rem; margin-bottom: var(--space-4);">
            You control every type of notification. Turn off what you don't want.
        </p>

        <label class="toggle-row">
            <input type="checkbox" name="notify_likes"
                   <?= (int)$prefs['notify_likes'] === 1 ? 'checked' : '' ?>>
            <span class="toggle-label">
                <strong>Likes</strong>
                <span class="toggle-hint">When someone likes your post</span>
            </span>
        </label>

        <label class="toggle-row">
            <input type="checkbox" name="notify_comments"
                   <?= (int)$prefs['notify_comments'] === 1 ? 'checked' : '' ?>>
            <span class="toggle-label">
                <strong>Comments</strong>
                <span class="toggle-hint">When someone comments on your post</span>
            </span>
        </label>

        <label class="toggle-row">
            <input type="checkbox" name="notify_follows"
                   <?= (int)$prefs['notify_follows'] === 1 ? 'checked' : '' ?>>
            <span class="toggle-label">
                <strong>New followers</strong>
                <span class="toggle-hint">When someone follows you</span>
            </span>
        </label>

        <label class="toggle-row">
            <input type="checkbox" name="notify_topic_posts"
                   <?= (int)$prefs['notify_topic_posts'] === 1 ? 'checked' : '' ?>>
            <span class="toggle-label">
                <strong>Topic activity</strong>
                <span class="toggle-hint">When someone posts in a topic you've joined</span>
            </span>
        </label>

        <div style="margin-top: var(--space-5); padding-top: var(--space-4); border-top: 1px solid var(--border);">
            <label class="toggle-row" style="border-bottom:none;">
                <input type="checkbox" name="notify_paused"
                       <?= (int)$prefs['notify_paused'] === 1 ? 'checked' : '' ?>>
                <span class="toggle-label">
                    <strong>Pause all notifications</strong>
                    <span class="toggle-hint">Temporarily silence everything. Nothing is lost — you'll see it in your inbox when you resume.</span>
                </span>
            </label>
        </div>

        <div style="display:flex; gap: var(--space-2); margin-top: var(--space-4);">
            <button type="submit">Save preferences</button>
            <a href="notifications.php" class="btn-secondary">View notifications</a>
        </div>
    </form>

    <p style="text-align:center; font-size:0.85rem; color:var(--muted); margin-top: var(--space-4);">
        Qarota doesn't use your notifications to sell ads or train algorithms.
        Every notification is triggered by a specific action someone took.
    </p>
</div>

<?php require 'includes/footer.php'; ?>