<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require 'includes/messages.php';   // messaging helpers
require 'includes/blocks.php';     // block helpers

// -----------------------------------------------------------------------------
// Auth
// -----------------------------------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$me   = (int) $_SESSION['user_id'];
$conv = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// -----------------------------------------------------------------------------
// Validate conversation + membership
// -----------------------------------------------------------------------------
if ($conv <= 0 || !user_in_conversation($pdo, $conv, $me)) {
    http_response_code(404);
    $page_title = __('messages_not_found');
    require 'includes/header.php';
    echo '<div class="card"><div class="empty">'
       . htmlspecialchars(__('messages_not_found'))
       . '</div></div>';
    require 'includes/footer.php';
    exit;
}

// -----------------------------------------------------------------------------
// Partner info
// -----------------------------------------------------------------------------
$partnerId = conversation_partner($pdo, $conv, $me);
$pStmt = $pdo->prepare(
    "SELECT id, username, display_name, avatar FROM users WHERE id = :id LIMIT 1"
);
$pStmt->execute([':id' => $partnerId]);
$partner = $pStmt->fetch();

// -----------------------------------------------------------------------------
// Block state (either direction disables the composer)
// -----------------------------------------------------------------------------
$i_blocked_them  = $partner ? is_blocked($pdo, $me, (int)$partner['id']) : false;
$they_blocked_me = $partner ? is_blocked($pdo, (int)$partner['id'], $me) : false;
$blocked_either  = $i_blocked_them || $they_blocked_me;

// Opening the thread marks all incoming messages as read
mark_conversation_read($pdo, $conv, $me);

// -----------------------------------------------------------------------------
// Load messages (oldest first)
// -----------------------------------------------------------------------------
$mStmt = $pdo->prepare(
    "SELECT id, sender_id, body, created_at, deleted_at
     FROM messages
     WHERE conversation_id = :c
     ORDER BY id ASC
     LIMIT 500"
);

$mStmt->execute([':c' => $conv]);
$messages = $mStmt->fetchAll();

$partner_name = $partner
    ? ($partner['display_name'] ?: $partner['username'])
    : '';

$page_title = $partner ? sprintf(__('messages_with'), $partner_name) : __('messages_title');
require 'includes/header.php';
?>

<div class="card" id="conversationCard" data-conv="<?= $conv ?>" data-me="<?= $me ?>">
    <p style="margin-top:0;">
        <a href="messages.php">← <?= __('messages_back_to_inbox') ?></a>
    </p>

    <?php if ($partner): ?>
        <div class="post-header" style="margin-bottom: var(--space-4); justify-content: space-between;">
            <div style="display:flex; align-items:center; gap: var(--space-3);">
                <?php if (!empty($partner['avatar'])): ?>
                    <img class="avatar avatar-small"
                         src="<?= htmlspecialchars($partner['avatar']) ?>" alt="">
                <?php else: ?>
                    <span class="avatar avatar-small avatar-placeholder">
                        <?= htmlspecialchars(mb_strtoupper(mb_substr($partner_name, 0, 1))) ?>
                    </span>
                <?php endif; ?>
                <div class="author">
                    <a href="profile.php?u=<?= urlencode($partner['username']) ?>"
                       data-user-id="<?= (int)$partner['id'] ?>">
                        <?= htmlspecialchars($partner_name) ?>
                    </a>
                </div>
            </div>

            <div style="display:flex; gap: var(--space-2); align-items:center;">
                <!-- Block / Unblock toggle (works regardless of current state) -->
                <form method="POST" action="block.php" class="block-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="target_id" value="<?= (int)$partner['id'] ?>">
                    <input type="hidden" name="block_action"
                           value="<?= $i_blocked_them ? 'unblock' : 'block' ?>">
                    <input type="hidden" name="redirect"
                           value="conversation.php?id=<?= $conv ?>">
                    <button type="submit"
                            class="<?= $i_blocked_them ? 'btn-secondary' : 'btn-danger' ?> btn-small"
                            <?php if (!$i_blocked_them): ?>
                                onclick="return confirm('<?= htmlspecialchars(__('block_confirm'), ENT_QUOTES) ?>');"
                            <?php endif; ?>>
                        <?= $i_blocked_them ? __('block_unblock') : __('block_button') ?>
                    </button>
                </form>

                <a href="report_message.php?conversation_id=<?= $conv ?>"
                   class="report-link"
                   aria-label="<?= __('report_title') ?>"
                   title="<?= __('report_title') ?>">
                    <i data-lucide="flag" style="width:14px;height:14px;"></i>
                    <?= __('report_title') ?>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Status banner for blocked conversations -->
    <?php if ($i_blocked_them): ?>
        <div class="blocked-banner"><?= __('block_you_blocked') ?></div>
    <?php elseif ($they_blocked_me): ?>
        <div class="blocked-banner blocked-them"><?= __('block_they_blocked') ?></div>
    <?php endif; ?>

    <div class="conversation-thread" id="thread">
        <p class="messages-empty" id="threadEmpty"
           style="<?= $messages ? 'display:none;' : '' ?>">
            <?= __('messages_thread_empty') ?>
        </p>
        <?php foreach ($messages as $m): ?>
            <?php $mine = ((int)$m['sender_id'] === $me); ?>
            <?php $is_deleted = !empty($m['deleted_at']); ?>
<div class="bubble <?= $mine ? 'bubble-mine' : 'bubble-theirs' ?> <?= $is_deleted ? 'bubble-deleted' : '' ?>"
     data-id="<?= (int)$m['id'] ?>">

    <div class="bubble-body">
        <?php if ($is_deleted): ?>
            <em class="bubble-deleted-text"><?= __('message_deleted') ?></em>
        <?php else: ?>
            <?= nl2br(htmlspecialchars($m['body'])) ?>
        <?php endif; ?>
    </div>

    <div class="bubble-time"><?= htmlspecialchars($m['created_at']) ?></div>

    <?php if (!$mine && !$is_deleted): ?>
        <a class="bubble-report"
           href="report_message.php?conversation_id=<?= $conv ?>&message_id=<?= (int)$m['id'] ?>"
           aria-label="<?= __('report_title') ?>"
           title="<?= __('report_title') ?>">
            <i data-lucide="flag" style="width:12px;height:12px;"></i>
        </a>
    <?php endif; ?>

    <?php if ($mine && !$is_deleted): ?>
        <button type="button"
                class="bubble-delete"
                data-message-id="<?= (int)$m['id'] ?>"
                aria-label="<?= __('message_delete') ?>"
                title="<?= __('message_delete') ?>">
            <i data-lucide="trash-2" style="width:12px;height:12px;"></i>
        </button>
    <?php endif; ?>
</div>
        <?php endforeach; ?>
    </div>

    <!-- Composer: hidden if either party has blocked the other -->
    <?php if ($blocked_either): ?>
        <p class="blocked-note">
            <?= $i_blocked_them
                ? __('block_note_you_blocked')
                : __('block_note_they_blocked') ?>
        </p>
    <?php else: ?>
        <form method="POST" action="message_send.php" class="stack message-form"
              id="messageForm" style="margin-top: var(--space-4);">
            <?= csrf_field() ?>
            <input type="hidden" name="conversation_id" value="<?= $conv ?>">
            <textarea name="body" id="messageBody" rows="3" maxlength="5000"
                      placeholder="<?= __('messages_placeholder') ?>" required></textarea>
            <button type="submit" id="sendBtn"><?= __('messages_send') ?></button>
        </form>
    <?php endif; ?>
</div>

<?php require 'includes/footer.php'; ?>