<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require_once __DIR__ . '/includes/posts.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

$notification_post_visibility_sql = post_visibility_filter('p', 'notification_post');
$stmt = $pdo->prepare("
    SELECT
        n.id, n.type, n.post_id, n.conversation_id, n.is_read, n.created_at,
        u.username AS actor_username,
        u.display_name AS actor_display_name,
        u.avatar AS actor_avatar,
        u.id AS actor_id,
        p.content AS post_content,
        t.name AS topic_name,
        t.slug AS topic_slug,
        (SELECT body FROM messages m
            WHERE m.conversation_id = n.conversation_id
            ORDER BY m.id DESC LIMIT 1) AS last_message_body
    FROM notifications n
    INNER JOIN users u ON n.actor_id = u.id
    LEFT JOIN posts p ON n.post_id = p.id
    LEFT JOIN topics t ON n.topic_id = t.id
    WHERE n.user_id = :me
      AND (
          n.post_id IS NULL
          OR (p.id IS NOT NULL AND $notification_post_visibility_sql)
      )
    ORDER BY n.created_at DESC
    LIMIT 100
");
$stmt->execute(array_merge(
    [':me' => $user_id],
    post_visibility_params($user_id, 'notification_post')
));
$notifications = $stmt->fetchAll();

require_once __DIR__ . '/includes/crypto.php';
foreach ($notifications as &$n) {
    if (isset($n['last_message_body'])) {
        $n['last_message_body'] = crypto_decrypt($n['last_message_body']);
    }
}
unset($n);

$stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :me AND is_read = 0");
$stmt->execute([':me' => $user_id]);

$page_title = __('notifications_title');
require 'includes/header.php';
?>

<h1><?= __('notifications_title') ?></h1>

<p style="text-align:right; margin-bottom: var(--space-4);">
    <a href="settings_notifications.php" class="btn-secondary btn-small">
        <i data-lucide="settings" style="width:14px;height:14px;"></i>
        Notification settings
    </a>
</p>

<?php if (empty($notifications)): ?>
    <div class="empty"><?= __('notifications_empty') ?></div>
<?php else: ?>
    <?php foreach ($notifications as $n): ?>
        <?php $actor_name = $n['actor_display_name'] ?: $n['actor_username']; ?>
        <div class="card notification <?= $n['is_read'] ? '' : 'unread' ?>">
            <div class="post-header">
                <?php if ($n['actor_avatar']): ?>
                    <img class="avatar avatar-small"
                         src="<?= htmlspecialchars($n['actor_avatar']) ?>"
                         alt="">
                <?php else: ?>
                    <div class="avatar avatar-small avatar-placeholder">
                        <?= strtoupper(substr($actor_name, 0, 1)) ?>
                    </div>
                <?php endif; ?>

                <div class="notification-body">
                    <a href="profile.php?u=<?= urlencode($n['actor_username']) ?>" data-user-id="<?= $n['actor_id'] ?>">
                        <strong><?= htmlspecialchars($actor_name) ?></strong>
                    </a>

                    <?php if ($n['type'] === 'like'): ?>
                        <?= __('notif_liked') ?>

                    <?php elseif ($n['type'] === 'comment'): ?>
                        <?= __('notif_commented') ?>

                    <?php elseif ($n['type'] === 'follow'): ?>
                        <?= __('notif_followed') ?>

                    <?php elseif ($n['type'] === 'topic_posted'): ?>
                        posted in
                        <a href="topic.php?slug=<?= urlencode($n['topic_slug']) ?>" style="font-weight:600;">
                            #<?= htmlspecialchars($n['topic_name']) ?>
                        </a>

                    <?php elseif ($n['type'] === 'message' && !empty($n['conversation_id'])): ?>
                        <?= __('notif_messaged_short') ?>
                        <a href="conversation.php?id=<?= (int)$n['conversation_id'] ?>" style="font-weight:600;">
                            <?= __('messages_view_thread') ?>
                        </a>

                    <?php elseif ($n['type'] === 'report_resolved'): ?>
                        Your report led to action.
                        <a href="my_reports.php" style="font-weight:600;">See details →</a>

                    <?php elseif ($n['type'] === 'report_dismissed'): ?>
                        Your report was reviewed.
                        <a href="my_reports.php" style="font-weight:600;">See details →</a>

                    <?php elseif ($n['type'] === 'verification_result'): ?>
                        Your verification application has an update.
                        <a href="verify_apply_status.php" style="font-weight:600;">See status →</a>
                    <?php endif; ?>

                    <?php if ($n['type'] === 'message' && !empty($n['last_message_body'])): ?>
                        <div class="notification-preview">
                            "<?= htmlspecialchars(mb_substr($n['last_message_body'], 0, 80)) ?><?= mb_strlen($n['last_message_body']) > 80 ? '…' : '' ?>"
                        </div>
                    <?php elseif (!empty($n['post_content'])): ?>
                        <div class="notification-preview">
                            "<?= htmlspecialchars(mb_substr($n['post_content'], 0, 80)) ?><?= mb_strlen($n['post_content']) > 80 ? '…' : '' ?>"
                        </div>
                    <?php endif; ?>

                    <div class="meta"><?= htmlspecialchars($n['created_at']) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="end-of-feed">
        <strong>That's everything</strong>
        You're up to date.
    </div>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>