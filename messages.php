<?php
require_once __DIR__ . '/includes/bootstrap.php';
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
        c.is_group,
        c.group_name,
        c.group_avatar,
        (SELECT user_id FROM conversation_members
         WHERE conversation_id = c.id AND user_id <> :me_partner_id
         ORDER BY joined_at ASC LIMIT 1) AS partner_id,
        (SELECT u.username FROM conversation_members cm2
         INNER JOIN users u ON u.id = cm2.user_id
         WHERE cm2.conversation_id = c.id AND cm2.user_id <> :me_username
         ORDER BY cm2.joined_at ASC LIMIT 1) AS partner_username,
        (SELECT u.display_name FROM conversation_members cm2
         INNER JOIN users u ON u.id = cm2.user_id
         WHERE cm2.conversation_id = c.id AND cm2.user_id <> :me_display_name
         ORDER BY cm2.joined_at ASC LIMIT 1) AS partner_name,
        (SELECT u.avatar FROM conversation_members cm2
         INNER JOIN users u ON u.id = cm2.user_id
         WHERE cm2.conversation_id = c.id AND cm2.user_id <> :me_avatar
         ORDER BY cm2.joined_at ASC LIMIT 1) AS partner_avatar,
        (SELECT COUNT(*) FROM conversation_members
         WHERE conversation_id = c.id) AS member_count,
        (SELECT body FROM messages m2
            WHERE m2.conversation_id = c.id
            ORDER BY m2.id DESC LIMIT 1) AS last_body,
        (SELECT COUNT(*) FROM messages m3
            WHERE m3.conversation_id = c.id
              AND m3.sender_id <> :me_unread
              AND (cm.last_read_at IS NULL OR m3.created_at > cm.last_read_at)) AS unread
     FROM conversations c
     INNER JOIN conversation_members cm ON cm.conversation_id = c.id AND cm.user_id = :me_member
     ORDER BY (c.last_message_at IS NULL) ASC, c.last_message_at DESC, c.id DESC"
);
$stmt->execute([
    ':me_partner_id' => $me,
    ':me_username' => $me,
    ':me_display_name' => $me,
    ':me_avatar' => $me,
    ':me_unread' => $me,
    ':me_member' => $me,
]);
$conversations = $stmt->fetchAll();

require_once __DIR__ . '/includes/crypto.php';
foreach ($conversations as &$c) {
    if (isset($c['last_body'])) {
        $c['last_body'] = crypto_decrypt($c['last_body']);
    }
}
unset($c);

$page_title = __('messages_title');
require 'includes/header.php';
?>

<!-- ============================================================
     MESSAGE SEARCH
     ============================================================ -->
<div class="message-search-card">
    <div class="message-search-input-wrap">
        <i data-lucide="search" class="message-search-icon"></i>
        <input type="text"
               id="messageSearch"
               class="message-search-input"
               placeholder="Search your messages…"
               autocomplete="off"
               maxlength="100">
        <button type="button" id="messageSearchClear" class="message-search-clear" hidden aria-label="Clear">
            <i data-lucide="x"></i>
        </button>
    </div>
    <div id="messageSearchResults" class="message-search-results" hidden></div>
</div>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:var(--space-3); flex-wrap:wrap; margin-bottom:var(--space-3);">
        <h2 style="margin:0;"><?= __('messages_title') ?></h2>
        <a href="new_group.php" class="btn-secondary btn-small">
            <i data-lucide="user-plus" style="width:14px;height:14px;vertical-align:middle;"></i>
            New group
        </a>
    </div>

    <?php if (!$conversations): ?>
        <div class="empty"><?= __('messages_empty') ?></div>
    <?php else: ?>
        <ul class="messages-list">
            <?php foreach ($conversations as $c): ?>
                <?php
                $is_group = (int)$c['is_group'] === 1;
                $display_name = $is_group
                    ? ($c['group_name'] ?: 'Group')
                    : ($c['partner_name'] ?: $c['partner_username'] ?: '');
                $display_avatar = $is_group ? $c['group_avatar'] : $c['partner_avatar'];
                ?>
                <li class="messages-item">
                    <a href="conversation.php?id=<?= (int)$c['conversation_id'] ?>">
                        <?php if (!empty($display_avatar)): ?>
                            <img class="avatar avatar-small"
                                 src="<?= htmlspecialchars($display_avatar) ?>"
                                 alt="">
                        <?php else: ?>
                            <span class="avatar avatar-small avatar-placeholder">
                                <?= htmlspecialchars(mb_strtoupper(mb_substr($display_name, 0, 1))) ?>
                            </span>
                        <?php endif; ?>

                        <span class="messages-meta">
                            <strong>
                                <?= htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8') ?>
                                <?php if ($is_group): ?>
                                    <span class="messages-group-count">
                                        · <?= (int)$c['member_count'] ?> <?= (int)$c['member_count'] === 1 ? 'member' : 'members' ?>
                                    </span>
                                <?php endif; ?>
                            </strong>
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

<script src="assets/js/message-search.js?v=<?= filemtime(__DIR__ . '/assets/js/message-search.js') ?>" defer></script>

<?php require 'includes/footer.php'; ?>