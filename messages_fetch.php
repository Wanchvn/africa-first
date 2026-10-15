<?php
require_once __DIR__ . '/includes/bootstrap.php';
/**
 * Polling endpoint: returns new messages since `after` in a conversation.
 * Called by interact.js every ~4 seconds while the user views the thread.
 *
 * Note: this endpoint deliberately does NOT block on user_blocks, because
 * a blocked user should still be able to *read* historical messages in a
 * thread they're already part of. Sends are blocked in message_send.php;
 * reads are allowed here.
 */
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/messages.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}

$me    = (int) $_SESSION['user_id'];
$conv  = isset($_GET['id'])    ? (int) $_GET['id']    : 0;
$after = isset($_GET['after']) ? (int) $_GET['after'] : 0;

// Must be a participant in the conversation
if ($conv <= 0 || !user_in_conversation($pdo, $conv, $me)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Not found.']);
    exit;
}

// Fetch only new messages after the last-known id
$stmt = $pdo->prepare(
    "SELECT id, sender_id, body, message_type, voice_path, voice_duration,
            image_path, video_path, video_duration, video_thumb,
            created_at, deleted_at
     FROM messages
     WHERE conversation_id = :c AND id > :after
     ORDER BY id ASC
     LIMIT 200"
);
$stmt->execute([':c' => $conv, ':after' => $after]);
$messages = $stmt->fetchAll();

require_once __DIR__ . '/includes/crypto.php';
foreach ($messages as &$m) {
    $m['body'] = crypto_decrypt($m['body']);
}
unset($m);

// Mark incoming as read BEFORE we compute unread_total, otherwise the
// count will include messages we're about to consider read.
mark_conversation_read($pdo, $conv, $me);

// ---- Unread total across ALL of the user's conversations ----
// interact.js only clears the nav badge when this is exactly 0.
$unreadStmt = $pdo->prepare("
    SELECT COUNT(*) AS c
    FROM messages m
    INNER JOIN conversation_members cm
        ON cm.conversation_id = m.conversation_id
       AND cm.user_id = :me
    WHERE m.sender_id <> :me
      AND m.is_read = 0
      AND m.deleted_at IS NULL
");
$unreadStmt->execute([':me' => $me]);
$unreadTotal = (int) $unreadStmt->fetchColumn();

// ---- Reactions ----
$reactionStates = fetch_message_reaction_states($pdo, $conv, $me);
foreach ($messages as &$m) {
    $m['reactions'] = empty($m['deleted_at'])
        ? ($reactionStates[(int)$m['id']] ?? [])
        : [];
}
unset($m);

$reactionUpdates = [];
foreach ($reactionStates as $messageId => $reactions) {
    $reactionUpdates[] = [
        'message_id' => $messageId,
        'reactions' => $reactions,
    ];
}

// ---- Typing indicators ----
$typingUsers = [];
$tStmt = $pdo->prepare(
    "SELECT ti.user_id, u.display_name, u.username
     FROM typing_indicators ti
     INNER JOIN users u ON u.id = ti.user_id
     WHERE ti.conversation_id = :c
       AND ti.user_id <> :me
       AND ti.updated_at > (NOW() - INTERVAL 4 SECOND)
     ORDER BY ti.updated_at DESC
     LIMIT 5"
);
$tStmt->execute([':c' => $conv, ':me' => $me]);
foreach ($tStmt->fetchAll() as $tu) {
    $typingUsers[] = [
        'user_id' => (int)$tu['user_id'],
        'display_name' => $tu['display_name'] ?: $tu['username'],
        'username' => $tu['username'],
    ];
}

// ---- Read receipt: has the partner read my LAST outgoing message? ----
// We look at the very last message in the conversation. If it's mine
// and is_read is set, the partner has "seen" up to and including it.
//
// For 1:1 conversations this is correct because the partner's
// mark_conversation_read flips is_read on every message addressed to them.
//
// For GROUP conversations, is_read on a single message only means "at
// least one other member read it", not "everyone read it". The JS side
// already has a separate group path (seen_count) it can use instead;
// we return null here so the client doesn't show a misleading "Seen".
$readInfo = ['last_own_id' => null, 'last_own_seen' => false];

$isGroup = function_exists('is_group_conversation')
    ? is_group_conversation($pdo, $conv)
    : false;

if (!$isGroup) {
    $lastMsg = $pdo->prepare(
        "SELECT id, sender_id, is_read
         FROM messages
         WHERE conversation_id = :c
         ORDER BY id DESC
         LIMIT 1"
    );
    $lastMsg->execute([':c' => $conv]);
    $lm = $lastMsg->fetch();

    if ($lm && (int)$lm['sender_id'] === $me) {
        $readInfo['last_own_id']   = (int)$lm['id'];
        $readInfo['last_own_seen'] = ((int)$lm['is_read'] === 1);
    }
}

echo json_encode([
    'success'         => true,
    'messages'        => $messages,
    'reaction_states' => $reactionUpdates,
    'typing_users'    => $typingUsers,
    'last_own_id'     => $readInfo['last_own_id'],
    'last_own_seen'   => $readInfo['last_own_seen'],
    'unread_total'    => $unreadTotal,
]);