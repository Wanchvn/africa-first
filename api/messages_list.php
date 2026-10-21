<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/messages.php';
require_once __DIR__ . '/../includes/crypto.php';

$user = api_require_admin($pdo);
$me = (int)$user['id'];

// Same query as messages.php on the web
$stmt = $pdo->prepare(
    "SELECT
        c.id AS conversation_id,
        c.is_group,
        c.group_name,
        c.group_avatar,
        (SELECT u.username FROM conversation_members cm2 INNER JOIN users u ON u.id = cm2.user_id WHERE cm2.conversation_id = c.id AND cm2.user_id <> :me_uname LIMIT 1) AS partner_username,
        (SELECT u.display_name FROM conversation_members cm2 INNER JOIN users u ON u.id = cm2.user_id WHERE cm2.conversation_id = c.id AND cm2.user_id <> :me_dname LIMIT 1) AS partner_name,
        (SELECT u.avatar FROM conversation_members cm2 INNER JOIN users u ON u.id = cm2.user_id WHERE cm2.conversation_id = c.id AND cm2.user_id <> :me_avatar LIMIT 1) AS partner_avatar,
        (SELECT body FROM messages m2 WHERE m2.conversation_id = c.id ORDER BY m2.id DESC LIMIT 1) AS last_body,
        (SELECT COUNT(*) FROM messages m3 WHERE m3.conversation_id = c.id AND m3.sender_id <> :me_unread AND (cm.last_read_at IS NULL OR m3.created_at > cm.last_read_at)) AS unread
     FROM conversations c
     INNER JOIN conversation_members cm ON cm.conversation_id = c.id AND cm.user_id = :me_member
     ORDER BY (c.last_message_at IS NULL) ASC, c.last_message_at DESC"
);
$stmt->execute([
    ':me_uname' => $me, ':me_dname' => $me, ':me_avatar' => $me,
    ':me_unread' => $me, ':me_member' => $me,
]);
$conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$formatted = [];
foreach ($conversations as $c) {
    $isGroup = (int)$c['is_group'] === 1;
    $name = $isGroup ? ($c['group_name'] ?: 'Group') : ($c['partner_name'] ?: $c['partner_username']);
    $avatar = $isGroup ? $c['group_avatar'] : $c['partner_avatar'];
    
    $lastBody = $c['last_body'] ? crypto_decrypt($c['last_body']) : 'No messages yet';
    $lastBody = mb_strimwidth($lastBody, 0, 40, '…');

    $formatted[] = [
        'id' => (int)$c['conversation_id'],
        'is_group' => $isGroup,
        'name' => $name,
        'avatar' => $avatar,
        'last_message' => $lastBody,
        'unread' => (int)$c['unread']
    ];
}

api_ok(['conversations' => $formatted]);