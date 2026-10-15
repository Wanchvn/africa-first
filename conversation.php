<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require 'includes/messages.php';
require 'includes/blocks.php';
require_once 'includes/conversations.php';

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
$isGroup = is_group_conversation($pdo, $conv);
$partner = null;
$group = null;
$groupName = '';
$memberCount = 0;
$membersById = [];

if ($isGroup) {
    $gStmt = $pdo->prepare(
        "SELECT group_name, group_avatar FROM conversations WHERE id = :c LIMIT 1"
    );
    $gStmt->execute([':c' => $conv]);
    $group = $gStmt->fetch();
    $groupName = !empty($group['group_name']) ? $group['group_name'] : 'Group';
    $memberCount = conversation_member_count($pdo, $conv);

    $mStmt = $pdo->prepare(
        "SELECT u.id, u.username, u.display_name, u.avatar, cm.role
         FROM conversation_members cm
         INNER JOIN users u ON u.id = cm.user_id
         WHERE cm.conversation_id = :c
         ORDER BY cm.joined_at ASC"
    );
    $mStmt->execute([':c' => $conv]);
    foreach ($mStmt->fetchAll() as $member) {
        $membersById[(int)$member['id']] = $member;
    }
} else {
    $partnerId = conversation_partner($pdo, $conv, $me);
    $pStmt = $pdo->prepare(
        "SELECT id, username, display_name, avatar FROM users WHERE id = :id LIMIT 1"
    );
    $pStmt->execute([':id' => $partnerId]);
    $partner = $pStmt->fetch();
}

// -----------------------------------------------------------------------------
// Block state
// -----------------------------------------------------------------------------
$i_blocked_them  = $partner ? is_blocked($pdo, $me, (int)$partner['id']) : false;
$they_blocked_me = $partner ? is_blocked($pdo, (int)$partner['id'], $me) : false;
$blocked_either  = $i_blocked_them || $they_blocked_me;

// Mark read BEFORE loading messages, so unread counts from other
// conversations are accurate and this thread's messages flip to read.
mark_conversation_read($pdo, $conv, $me);

// -----------------------------------------------------------------------------
// Load messages — cap the tail, not the head
// -----------------------------------------------------------------------------
// We grab the LAST 500 messages (DESC + reverse) rather than the first 500.
// For long threads, the recent messages are what matters; the first 500 would
// silently hide the newest.
$mStmt = $pdo->prepare(
    "SELECT id, sender_id, body, message_type, voice_path, voice_duration,
            image_path, video_path, video_duration, video_thumb,
            created_at, deleted_at
     FROM messages
     WHERE conversation_id = :c
     ORDER BY id DESC
     LIMIT 500"
);
$mStmt->execute([':c' => $conv]);
$messages = array_reverse($mStmt->fetchAll());

require_once __DIR__ . '/includes/crypto.php';
foreach ($messages as &$m) {
    $m['body'] = crypto_decrypt($m['body']);
}
unset($m);

$reactionStates = fetch_message_reaction_states($pdo, $conv, $me);
foreach ($messages as &$m) {
    $m['reactions'] = empty($m['deleted_at'])
        ? ($reactionStates[(int)$m['id']] ?? [])
        : [];
}
unset($m);

// ---- Read receipt for initial render ----
$lastOwnId   = null;
$lastOwnSeen = false;
$lastOwnAt   = null;
$seenCount   = 0;

$lastMsgQ = $pdo->prepare(
    "SELECT id, sender_id, is_read, created_at
     FROM messages
     WHERE conversation_id = :c
     ORDER BY id DESC
     LIMIT 1"
);
$lastMsgQ->execute([':c' => $conv]);
$lm = $lastMsgQ->fetch();

if ($lm && (int)$lm['sender_id'] === $me) {
    $lastOwnId   = (int)$lm['id'];
    $lastOwnSeen = ((int)$lm['is_read'] === 1);
    $lastOwnAt   = $lm['created_at'];
}

// For 1:1, "last_own_seen" only makes sense if the partner actually read it.
// But is_read on a message is set by mark_conversation_read() — which runs
// when the PARTNER opens this thread. We can't infer partner state from
// our own view; the server-side flag is what we trust. Leave as-is.

if ($isGroup && $lastOwnAt) {
    $lrStmt = $pdo->prepare(
        "SELECT user_id, last_read_at FROM conversation_members
         WHERE conversation_id = :c AND user_id <> :me"
    );
    $lrStmt->execute([':c' => $conv, ':me' => $me]);
    foreach ($lrStmt->fetchAll() as $row) {
        if (!empty($row['last_read_at']) && $row['last_read_at'] >= $lastOwnAt) {
            $seenCount++;
        }
    }
}

$partner_name = $partner
    ? ($partner['display_name'] ?: $partner['username'])
    : '';

$isAdmin = $isGroup && is_conversation_admin($pdo, $conv, $me);
$followsStmt = $pdo->prepare(
    "SELECT u.id, u.username, u.display_name, u.avatar
     FROM follows f
     INNER JOIN users u ON u.id = f.following_id
     WHERE f.follower_id = :me
       AND u.id NOT IN (
           SELECT cm.user_id FROM conversation_members cm WHERE cm.conversation_id = :conv
       )
     ORDER BY COALESCE(NULLIF(u.display_name, ''), u.username), u.username"
);
$followsStmt->execute([':me' => $me, ':conv' => $conv]);
$followedUsers = $followsStmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = $isGroup
    ? $groupName
    : ($partner ? sprintf(__('messages_with'), $partner_name) : __('messages_title'));
require 'includes/header.php';
?>

<div class="card" id="conversationCard"
     data-conv="<?= $conv ?>"
     data-me="<?= $me ?>"
     data-is-group="<?= $isGroup ? '1' : '0' ?>"
     data-report-label="<?= htmlspecialchars(__('report_title'), ENT_QUOTES, 'UTF-8') ?>"
     data-react-label="<?= htmlspecialchars(__('react_add'), ENT_QUOTES, 'UTF-8') ?>"
     data-seen-label="<?= htmlspecialchars(__('seen')) ?>"
     data-last-own-id="<?= $lastOwnId ?: '' ?>"
     data-last-own-seen="<?= $lastOwnSeen ? '1' : '0' ?>"
     data-seen-count="<?= (int)$seenCount ?>">
    <p style="margin-top:0;">
        <a href="messages.php">← <?= __('messages_back_to_inbox') ?></a>
    </p>

    <?php if ($isGroup): ?>
        <div class="post-header" style="margin-bottom: var(--space-4); justify-content: space-between;">
            <button type="button" id="groupInfoBtn" class="group-header-toggle" aria-expanded="false" aria-controls="groupPanel">
                <div class="group-header-info">
                    <?php if (!empty($group['group_avatar'])): ?>
                        <img class="avatar avatar-small"
                             src="<?= htmlspecialchars($group['group_avatar'], ENT_QUOTES, 'UTF-8') ?>"
                             alt="">
                    <?php else: ?>
                        <span class="avatar avatar-small avatar-placeholder">
                            <?= htmlspecialchars(mb_strtoupper(mb_substr($groupName, 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                    <div>
                        <div class="group-name"><?= htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="group-meta"><?= $memberCount ?> members</div>
                    </div>
                </div>
                <span class="group-info-icon" aria-hidden="true"><i data-lucide="info" style="width:16px;height:16px;"></i></span>
            </button>

            <div style="display:flex; gap: var(--space-2); align-items:center;">
                <span class="group-block-placeholder" aria-hidden="true"></span>
                <a href="report_message.php?conversation_id=<?= $conv ?>"
                   class="report-link"
                   aria-label="<?= __('report_title') ?>"
                   title="<?= __('report_title') ?>">
                    <i data-lucide="flag" style="width:14px;height:14px;"></i>
                    <?= __('report_title') ?>
                </a>
            </div>
        </div>

        <div class="group-panel" id="groupPanel" hidden>
            <?php if ($isAdmin): ?>
                <form method="POST" action="group_avatar.php" enctype="multipart/form-data" class="group-panel-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="conversation_id" value="<?= $conv ?>">
                    <div class="group-panel-header">
                        <?php if (!empty($group['group_avatar'])): ?>
                            <img class="avatar avatar-large" src="<?= htmlspecialchars($group['group_avatar'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                        <?php else: ?>
                            <span class="avatar avatar-large avatar-placeholder">
                                <?= htmlspecialchars(mb_strtoupper(mb_substr($groupName, 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php endif; ?>
                        <div class="group-panel-actions">
                            <label class="file-upload-label" for="groupAvatarInput"><?= __('group_change_avatar') ?></label>
                            <input id="groupAvatarInput" type="file" name="group_avatar" accept="image/jpeg,image/png,image/webp" required>
                            <button type="submit" class="btn-small"><?= __('group_change_avatar') ?></button>
                        </div>
                    </div>
                </form>

                <form method="POST" action="group_rename.php" class="group-panel-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="conversation_id" value="<?= $conv ?>">
                    <label for="groupRenameInput"><?= __('group_rename') ?></label>
                    <div class="group-rename-row">
                        <input id="groupRenameInput" type="text" name="name" value="<?= htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') ?>" maxlength="100" required>
                        <button type="submit" class="btn-small"><?= __('group_rename') ?></button>
                    </div>
                </form>
            <?php endif; ?>

            <h3><?= $memberCount === 1 ? __('group_member_count_one') : sprintf(__('group_member_count'), $memberCount) ?></h3>

            <?php foreach ($membersById as $memberId => $member): ?>
                <?php $memberRole = $member['role'] ?? 'member'; ?>
                <div class="group-member-row">
                    <?php if (!empty($member['avatar'])): ?>
                        <img class="avatar avatar-small" src="<?= htmlspecialchars($member['avatar'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                    <?php else: ?>
                        <span class="avatar avatar-small avatar-placeholder">
                            <?= htmlspecialchars(mb_strtoupper(mb_substr(($member['display_name'] ?: $member['username']), 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                    <div>
                        <div class="group-member-name">
                            <?= htmlspecialchars(($member['display_name'] ?: $member['username']), ENT_QUOTES, 'UTF-8') ?>
                            <?php if ($memberRole === 'admin'): ?>
                                <span class="admin-pill"><?= __('group_admin_badge') ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="group-member-handle">@<?= htmlspecialchars($member['username'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>

                    <?php if ($isAdmin && (int)$memberId !== $me): ?>
                        <div class="member-actions">
                            <form method="POST" action="group_promote.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="conversation_id" value="<?= $conv ?>">
                                <input type="hidden" name="user_id" value="<?= (int)$memberId ?>">
                                <input type="hidden" name="action" value="<?= $memberRole === 'admin' ? 'demote' : 'promote' ?>">
                                <button type="submit" class="btn-small btn-secondary"><?= $memberRole === 'admin' ? __('group_demote') : __('group_promote') ?></button>
                            </form>
                            <form method="POST" action="group_remove_member.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="conversation_id" value="<?= $conv ?>">
                                <input type="hidden" name="user_id" value="<?= (int)$memberId ?>">
                                <button type="submit" class="btn-small btn-danger"><?= __('group_remove_member') ?></button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if ($isAdmin): ?>
                <div class="group-add-member">
                    <h3><?= __('group_add_member') ?></h3>
                    <input type="search" id="groupMemberSearch" class="group-member-search" placeholder="<?= htmlspecialchars(__('group_search_placeholder'), ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
                    <div class="group-add-member-list">
                        <?php foreach ($followedUsers as $user): ?>
                            <form method="POST" action="group_add_member.php" class="group-add-member-form" data-member-search="<?= htmlspecialchars(mb_strtolower(($user['display_name'] ?: $user['username']) . ' ' . $user['username']), ENT_QUOTES, 'UTF-8') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="conversation_id" value="<?= $conv ?>">
                                <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                <button type="submit" class="group-member-option">
                                    <?php if (!empty($user['avatar'])): ?>
                                        <img class="avatar avatar-small" src="<?= htmlspecialchars($user['avatar'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                                    <?php else: ?>
                                        <span class="avatar avatar-small avatar-placeholder">
                                            <?= htmlspecialchars(mb_strtoupper(mb_substr(($user['display_name'] ?: $user['username']), 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php endif; ?>
                                    <span>
                                        <span class="group-member-name"><?= htmlspecialchars($user['display_name'] ?: $user['username'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="group-member-handle">@<?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </span>
                                </button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" action="group_leave.php" class="group-leave-form">
                <?= csrf_field() ?>
                <input type="hidden" name="conversation_id" value="<?= $conv ?>">
                <button type="submit" class="btn btn-secondary group-leave-btn" data-confirm="<?= htmlspecialchars(__('group_leave_confirm'), ENT_QUOTES, 'UTF-8') ?>">
    <?= __('group_leave') ?>
</button>
            </form>
        </div>
    <?php elseif ($partner): ?>
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
                <form method="POST" action="block.php" class="block-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="target_id" value="<?= (int)$partner['id'] ?>">
                    <input type="hidden" name="block_action"
                           value="<?= $i_blocked_them ? 'unblock' : 'block' ?>">
                    <input type="hidden" name="redirect"
                           value="conversation.php?id=<?= $conv ?>">
                    <button type="submit"
        class="<?= $i_blocked_them ? 'btn-secondary' : 'btn-danger' ?> btn-small">
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
            <?php
            $mine       = ((int)$m['sender_id'] === $me);
            $is_deleted = !empty($m['deleted_at']);
            $is_voice   = (($m['message_type'] ?? 'text') === 'voice') && !empty($m['voice_path']);
            $is_image   = (($m['message_type'] ?? 'text') === 'image') && !empty($m['image_path']);
            $is_video   = (($m['message_type'] ?? 'text') === 'video') && !empty($m['video_path']);
            ?>
            <div class="bubble <?= $mine ? 'bubble-mine' : 'bubble-theirs' ?> <?= $is_deleted ? 'bubble-deleted' : '' ?>"
                 data-id="<?= (int)$m['id'] ?>">

                <?php if ($isGroup && !$mine): ?>
                    <?php $sender = $membersById[(int)$m['sender_id']] ?? null; ?>
                    <?php if ($sender): ?>
                        <?php $senderName = $sender['display_name'] ?: $sender['username']; ?>
                        <div class="bubble-sender">
                            <?php if (!empty($sender['avatar'])): ?>
                                <img class="avatar avatar-tiny"
                                     src="<?= htmlspecialchars($sender['avatar'], ENT_QUOTES, 'UTF-8') ?>"
                                     alt="">
                            <?php else: ?>
                                <span class="avatar avatar-tiny avatar-placeholder">
                                    <?= htmlspecialchars(mb_strtoupper(mb_substr($senderName, 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                            <a href="profile.php?u=<?= urlencode($sender['username']) ?>"
                               data-user-id="<?= (int)$sender['id'] ?>">
                                <?= htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="bubble-body">
                    <?php if ($is_deleted): ?>
                        <em class="bubble-deleted-text"><?= __('message_deleted') ?></em>

                    <?php elseif ($is_voice): ?>
                        <?php $dur = (int)($m['voice_duration'] ?? 0); ?>
                        <div class="voice-message">
                            <audio controls preload="metadata" class="voice-player">
                                <source src="voice_serve.php?id=<?= (int)$m['id'] ?>">
                            </audio>
                            <div class="voice-duration">
                                <?= sprintf('%d:%02d', intdiv($dur, 60), $dur % 60) ?>
                            </div>
                        </div>

                    <?php elseif ($is_image): ?>
                        <a href="image_serve.php?id=<?= (int)$m['id'] ?>"
                           target="_blank" rel="noopener" class="dm-image-link">
                            <img class="dm-image"
                                 src="image_serve.php?id=<?= (int)$m['id'] ?>"
                                 alt="<?= __('image_alt') ?>"
                                 loading="lazy">
                        </a>

                    <?php elseif ($is_video): ?>
                        <div class="video-message">
                            <?php
                            // video_thumb may be a stored path (legacy) or a flag.
                            // Prefer the serving endpoint for consistent auth checks.
                            $posterUrl = 'video_thumb_serve.php?id=' . (int)$m['id'];
                            ?>
                            <video controls preload="metadata" class="dm-video"
                                   <?php if (!empty($m['video_thumb'])): ?>
                                       poster="<?= htmlspecialchars($posterUrl, ENT_QUOTES, 'UTF-8') ?>"
                                   <?php endif; ?>>
                                <source src="video_serve.php?id=<?= (int)$m['id'] ?>">
                                Your browser does not support video.
                            </video>
                            <?php $vdur = (int)($m['video_duration'] ?? 0); ?>
                            <?php if ($vdur > 0): ?>
                                <div class="video-duration">
                                    <?= sprintf('%d:%02d', intdiv($vdur, 60), $vdur % 60) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                    <?php elseif (($m['message_type'] ?? 'text') === 'system'): ?>
                        <div class="system-message"><?= nl2br(htmlspecialchars($m['body'])) ?></div>

                    <?php else: ?>
                        <?= nl2br(htmlspecialchars($m['body'])) ?>
                    <?php endif; ?>
                </div>

                <div class="bubble-time">
                    <?= htmlspecialchars($m['created_at']) ?>
                    <?php if ($mine && !$is_deleted && $lastOwnId === (int)$m['id']): ?>
                        <?php
                        $displaySeen = false;
                        $seenText = '';
                        if ($isGroup && $seenCount > 0) {
                            $displaySeen = true;
                            $seenText = sprintf(__('seen_by_n'), $seenCount);
                        } elseif (!$isGroup && $lastOwnSeen) {
                            $displaySeen = true;
                            $seenText = __('seen');
                        }
                        ?>
                        <?php if ($displaySeen): ?>
                            <span class="seen-pill">· <?= htmlspecialchars($seenText, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if (!$is_deleted): ?>
                    <div class="bubble-reactions" data-message-id="<?= (int)$m['id'] ?>">
                        <?php foreach (($m['reactions'] ?? []) as $r): ?>
                            <button type="button"
                                    class="reaction-chip <?= $r['mine'] ? 'reaction-mine' : '' ?>"
                                    data-message-id="<?= (int)$m['id'] ?>"
                                    data-emoji="<?= htmlspecialchars($r['emoji'], ENT_QUOTES, 'UTF-8') ?>">
                                <span class="reaction-emoji"><?= htmlspecialchars($r['emoji']) ?></span>
                                <span class="reaction-count"><?= (int)$r['count'] ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <button type="button"
                            class="bubble-react-trigger"
                            data-message-id="<?= (int)$m['id'] ?>"
                            title="<?= htmlspecialchars(__('react_add'), ENT_QUOTES, 'UTF-8') ?>"
                            aria-label="<?= htmlspecialchars(__('react_add'), ENT_QUOTES, 'UTF-8') ?>">
                        <i data-lucide="smile-plus" style="width:12px;height:12px;"></i>
                    </button>

                    <div class="reaction-picker" data-message-id="<?= (int)$m['id'] ?>" hidden>
                        <?php foreach (['👍', '❤️', '😂', '😮', '😢', '🙏'] as $e): ?>
                            <button type="button"
                                    class="reaction-picker-btn"
                                    data-message-id="<?= (int)$m['id'] ?>"
                                    data-emoji="<?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($e) ?></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

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

    <!-- Typing indicator -->
    <div class="typing-indicator" id="typingIndicator" style="display:none;" aria-live="polite">
        <span class="typing-dots">
            <span></span><span></span><span></span>
        </span>
        <span class="typing-name"><?= htmlspecialchars($partner_name) ?></span>
        <span class="typing-text"><?= __('typing_indicator') ?></span>
    </div>

    <!-- Composer -->
    <?php if ($blocked_either): ?>
        <p class="blocked-note">
            <?= $i_blocked_them
                ? __('block_note_you_blocked')
                : __('block_note_they_blocked') ?>
        </p>
    <?php else: ?>
        <form method="POST" action="message_send.php" class="stack message-form"
              id="messageForm" style="margin-top: var(--space-4);"
              data-voice-max="60">
            <?= csrf_field() ?>
            <input type="hidden" name="conversation_id" value="<?= $conv ?>">
            <textarea name="body" id="messageBody" rows="3" maxlength="5000"
                      placeholder="<?= __('messages_placeholder') ?>"></textarea>

            <div class="voice-recorder" id="voiceRecorder" style="display:none;">
                <span class="voice-rec-dot"></span>
                <span class="voice-rec-time" id="voiceRecTime">0:00</span>
                <button type="button" class="voice-rec-cancel" id="voiceCancelBtn"
                        aria-label="<?= __('voice_cancel') ?>">
                    <i data-lucide="x" style="width:16px;height:16px;"></i>
                </button>
                <button type="button" class="voice-rec-send" id="voiceSendBtn"
                        aria-label="<?= __('voice_send') ?>">
                    <i data-lucide="send" style="width:16px;height:16px;"></i>
                </button>
            </div>

            <div class="message-actions">
                <button type="button" id="imageBtn" class="mic-btn"
                        aria-label="<?= __('image_attach') ?>"
                        title="<?= __('image_attach') ?>">
                    <i data-lucide="image" style="width:18px;height:18px;"></i>
                </button>

                <button type="button" id="videoBtn" class="mic-btn"
                        aria-label="<?= __('video_record') ?>"
                        title="<?= __('video_record') ?>">
                    <i data-lucide="video" style="width:18px;height:18px;"></i>
                </button>

                <button type="button" id="micBtn" class="mic-btn"
                        aria-label="<?= __('voice_record') ?>"
                        title="<?= __('voice_record') ?>">
                    <i data-lucide="mic" style="width:18px;height:18px;"></i>
                </button>

                <button type="submit" id="sendBtn"><?= __('messages_send') ?></button>
            </div>

            <input type="file" id="imageInput" name="image"
                   accept="image/jpeg,image/png,image/gif,image/webp"
                   style="display:none;">

            <input type="file" id="videoInput" name="video"
                   accept="video/mp4,video/webm,video/quicktime"
                   style="display:none;">
        </form>
    <?php endif; ?>
</div>

<?php if ($isGroup && $isAdmin): ?>
<script>
(function () {
    var search = document.getElementById('groupMemberSearch');
    if (!search) return;
    var forms = Array.prototype.slice.call(document.querySelectorAll('.group-add-member-form'));
    search.addEventListener('input', function () {
        var query = search.value.trim().toLowerCase();
        forms.forEach(function (form) {
            var text = (form.getAttribute('data-member-search') || '').toLowerCase();
            form.hidden = query && text.indexOf(query) === -1;
        });
    });
})();
</script>
<?php endif; ?>

<script>
/* ============================================================
   ANCHOR SCROLL — jump to a message from search
   ============================================================ */
(function () {
    var hash = window.location.hash;
    if (!hash || hash.indexOf('#message-') !== 0) return;

    var messageId = hash.replace('#message-', '');
    if (!messageId) return;

    var attempts = 0;
    var interval = setInterval(function () {
        attempts++;
        var bubble = document.querySelector('.bubble[data-id="' + messageId + '"]');
        if (bubble) {
            clearInterval(interval);
            bubble.scrollIntoView({ behavior: 'smooth', block: 'center' });
            bubble.classList.add('bubble-highlighted');
            setTimeout(function () {
                bubble.classList.remove('bubble-highlighted');
            }, 2400);
        }
        if (attempts > 20) clearInterval(interval);
    }, 200);
})();




/* Group leave button — uses qarotaConfirm */
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.group-leave-btn');
    if (!btn) return;
    e.preventDefault();
    var form = btn.closest('form');
    qarotaConfirm(btn.dataset.confirm || 'Leave this group?', {
        title: 'Leave group',
        okText: 'Leave',
        cancelText: 'Cancel',
        danger: true
    }).then(function (ok) {
        if (ok && form) form.submit();
    });
});
</script>

<?php require 'includes/footer.php'; ?>