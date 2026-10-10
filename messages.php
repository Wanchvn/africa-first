<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require 'includes/messages.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$me = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare(
    "SELECT
        c.id AS conversation_id,
        c.last_message_at,
        CASE WHEN c.user1_id = :me THEN c.user2_id ELSE c.user1_id END AS partner_id,
        u.username      AS partner_username,
        u.display_name  AS partner_name,
        u.avatar        AS partner_avatar,
        (SELECT body FROM messages m2
            WHERE m2.conversation_id = c.id
            ORDER BY m2.id DESC LIMIT 1) AS last_body,
        (SELECT COUNT(*) FROM messages m3
            WHERE m3.conversation_id = c.id
              AND m3.sender_id <> :me
              AND m3.is_read = 0) AS unread
     FROM conversations c
     JOIN users u
       ON u.id = CASE WHEN c.user1_id = :me THEN c.user2_id ELSE c.user1_id END
     WHERE c.user1_id = :me OR c.user2_id = :me
     ORDER BY (c.last_message_at IS NULL) ASC, c.last_message_at DESC, c.id DESC"
);
$stmt->execute([':me' => $me]);
$conversations = $stmt->fetchAll();

$page_title = __('messages_title');
require 'includes/header.php';
?>

<div class="card">
    <h2 style="margin-top:0;"><?= __('messages_title') ?></h2>

    <?php if (!$conversations): ?>
        <div class="empty"><?= __('messages_empty') ?></div>
    <?php else: ?>
        <ul class="messages-list">
            <?php foreach ($conversations as $c): ?>
                <?php
                $partner_name = $c['partner_name'] ?: $c['partner_username'];
                ?>
                <li class="messages-item">
                    <a href="conversation.php?id=<?= (int)$c['conversation_id'] ?>">
                        <?php if (!empty($c['partner_avatar'])): ?>
                            <img class="avatar avatar-small"
                                 src="<?= htmlspecialchars($c['partner_avatar']) ?>"
                                 alt="">
                        <?php else: ?>
                            <span class="avatar avatar-small avatar-placeholder">
                                <?= htmlspecialchars(mb_strtoupper(mb_substr($partner_name, 0, 1))) ?>
                            </span>
                        <?php endif; ?>

                        <span class="messages-meta">
                            <strong><?= htmlspecialchars($partner_name) ?></strong>
                            <span class="messages-preview">
                                <?= $c['last_body'] !== null
                                    ? htmlspecialchars(mb_strimwidth($c['last_body'], 0, 80, '…'))
                                    : __('messages_no_messages_yet') ?>
                            </span>
                        </span>

                        <?php if ((int)$c['unread'] > 0): ?>
                            <span class="badge"><?= (int)$c['unread'] ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php require 'includes/footer.php'; ?>