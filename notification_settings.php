<?php
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

// ---- Fetch current preferences ----
$stmt = $pdo->prepare("
    SELECT notify_messages, notify_likes, notify_comments,
           notify_follows, notify_topic_posts, notify_paused
    FROM users WHERE id = :id
");
$stmt->execute([':id' => $user_id]);
$prefs = $stmt->fetch();

// ---- Handle save ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $notify_messages     = !empty($_POST['notify_messages'])     ? 1 : 0;
    $notify_likes        = !empty($_POST['notify_likes'])        ? 1 : 0;
    $notify_comments     = !empty($_POST['notify_comments'])     ? 1 : 0;
    $notify_follows      = !empty($_POST['notify_follows'])      ? 1 : 0;
    $notify_topic_posts  = !empty($_POST['notify_topic_posts'])  ? 1 : 0;
    $notify_paused       = !empty($_POST['notify_paused'])       ? 1 : 0;

    $stmt = $pdo->prepare("
        UPDATE users
        SET notify_messages = :messages,
            notify_likes = :likes,
            notify_comments = :comments,
            notify_follows = :follows,
            notify_topic_posts = :topic_posts,
            notify_paused = :paused
        WHERE id = :id
    ");
    $stmt->execute([
        ':messages'    => $notify_messages,
        ':likes'       => $notify_likes,
        ':comments'    => $notify_comments,
        ':follows'     => $notify_follows,
        ':topic_posts' => $notify_topic_posts,
        ':paused'      => $notify_paused,
        ':id'          => $user_id,
    ]);

    // ---- Refresh ----
    $stmt = $pdo->prepare("
        SELECT notify_messages, notify_likes, notify_comments,
               notify_follows, notify_topic_posts, notify_paused
        FROM users WHERE id = :id
    ");
    $stmt->execute([':id' => $user_id]);
    $prefs = $stmt->fetch();

    $message = 'Notification preferences saved.';
}

$page_title = 'Notification settings';
require 'includes/header.php';
?>

<h1>Notification settings</h1>

<p style="color: var(--muted); margin-bottom: var(--space-5);">
    Choose what you want to be notified about. You can change this any time.
</p>

<?php if ($message): ?>
    <div class="message" style="background: #E8F5E9; border-left-color: #4CAF50;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<form method="POST" class="stack">
    <?= csrf_field() ?>

    <!-- ============================================================
         PAUSE EVERYTHING
         ============================================================ -->
    <div class="card" style="border-left: 4px solid var(--terracotta);">
        <h2 style="margin-top:0;">Pause everything</h2>
        <p style="color: var(--muted); font-size: 0.9rem; margin-bottom: var(--space-3);">
            Turn off all notifications temporarily. You'll still see them in the notifications page when you visit — you just won't be interrupted.
        </p>
        <label class="toggle-row">
            <input type="checkbox"
                   name="notify_paused"
                   value="1"
                   <?= $prefs['notify_paused'] ? 'checked' : '' ?>>
            <span class="toggle-label">
                <strong>Pause all notifications</strong>
            </span>
        </label>
    </div>

    <!-- ============================================================
         ACTIVITY
         ============================================================ -->
    <div class="card">
        <h2 style="margin-top:0;">Activity</h2>

        <!-- Messages — most important, so it comes first -->
        <label class="toggle-row">
            <input type="checkbox"
                   name="notify_messages"
                   value="1"
                   <?= $prefs['notify_messages'] ? 'checked' : '' ?>>
            <span class="toggle-label">
                <strong><?= __('notify_messages_label') ?></strong>
                <span class="toggle-hint"><?= __('notify_messages_hint') ?></span>
            </span>
        </label>

        <label class="toggle-row">
            <input type="checkbox"
                   name="notify_likes"
                   value="1"
                   <?= $prefs['notify_likes'] ? 'checked' : '' ?>>
            <span class="toggle-label">
                <strong>Likes</strong>
                <span class="toggle-hint">When someone likes your post</span>
            </span>
        </label>

        <label class="toggle-row">
            <input type="checkbox"
                   name="notify_comments"
                   value="1"
                   <?= $prefs['notify_comments'] ? 'checked' : '' ?>>
            <span class="toggle-label">
                <strong>Comments</strong>
                <span class="toggle-hint">When someone comments on your post</span>
            </span>
        </label>

        <label class="toggle-row">
            <input type="checkbox"
                   name="notify_follows"
                   value="1"
                   <?= $prefs['notify_follows'] ? 'checked' : '' ?>>
            <span class="toggle-label">
                <strong>Follows</strong>
                <span class="toggle-hint">When someone follows you</span>
            </span>
        </label>

        <label class="toggle-row">
            <input type="checkbox"
                   name="notify_topic_posts"
                   value="1"
                   <?= $prefs['notify_topic_posts'] ? 'checked' : '' ?>>
            <span class="toggle-label">
                <strong>Topic posts</strong>
                <span class="toggle-hint">When someone posts in a topic you've joined</span>
            </span>
        </label>
    </div>

    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <button type="submit">Save preferences</button>
        <a href="notifications.php" class="btn-secondary">Cancel</a>
    </div>
</form>

<?php require 'includes/footer.php'; ?>