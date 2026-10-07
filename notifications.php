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

$stmt = $pdo->prepare("
    SELECT
        n.id, n.type, n.post_id, n.is_read, n.created_at,
        u.username AS actor_username,
        u.display_name AS actor_display_name,
        u.avatar AS actor_avatar,
        u.id AS actor_id,
        p.content AS post_content
    FROM notifications n
    INNER JOIN users u ON n.actor_id = u.id
    LEFT JOIN posts p ON n.post_id = p.id
    WHERE n.user_id = :me
    ORDER BY n.created_at DESC
    LIMIT 100
");
$stmt->execute([':me' => $user_id]);
$notifications = $stmt->fetchAll();

$stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :me AND is_read = 0");
$stmt->execute([':me' => $user_id]);

$page_title = __('notifications_title');
require 'includes/header.php';
?>

<h1><?= __('notifications_title') ?></h1>

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
                    <a href="profile.php?u=<?= urlencode($n['actor_username']) ?>">
                        <strong><?= htmlspecialchars($actor_name) ?></strong>
                    </a>

                    <?php if ($n['type'] === 'like'): ?>
                        <?= __('notif_liked') ?>
                    <?php elseif ($n['type'] === 'comment'): ?>
                        <?= __('notif_commented') ?>
                    <?php elseif ($n['type'] === 'follow'): ?>
                        <?= __('notif_followed') ?>
                    <?php endif; ?>

                    <?php if ($n['post_content']): ?>
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