<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/messages.php';
require_once __DIR__ . '/../includes/crypto.php';

$user = api_require_auth($pdo);
$me = (int)$user['id'];
$conv = (int)($_GET['conversation_id'] ?? 0);

if ($conv <= 0 || !user_in_conversation($pdo, $conv, $me)) {
    api_error(404, 'Conversation not found.');
}

mark_conversation_read($pdo, $conv, $me);

$stmt = $pdo->prepare("
    SELECT id, sender_id, body, message_type, created_at, deleted_at
    FROM messages
    WHERE conversation_id = :c
    ORDER BY id ASC
    LIMIT 100
");
$stmt->execute([':c' => $conv]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

$formatted = [];
foreach ($messages as $m) {
    $msg_type = $m['message_type'] ?: 'text';
    $decrypted_body = $m['body'];

    // ONLY decrypt if it's a text message
    if ($msg_type === 'text' && function_exists('crypto_decrypt')) {
        try {
            $decrypted_body = crypto_decrypt($m['body']);
        } catch (Throwable $e) {}
    }

    $formatted[] = [
        'id' => (int)$m['id'],
        'sender_id' => (int)$m['sender_id'],
        'body' => $m['deleted_at'] ? 'Message deleted' : $decrypted_body,
        'type' => $msg_type,
        'created_at' => $m['created_at'],
        'is_deleted' => (bool)$m['deleted_at']
    ];
}

// Check typing indicator
$stmt = $pdo->prepare("
    SELECT 1 FROM typing_indicators 
    WHERE conversation_id = :c AND user_id != :me AND updated_at > NOW() - INTERVAL 3 SECOND
");
$stmt->execute([':c' => $conv, ':me' => $me]);
$is_typing = (bool)$stmt->fetchColumn();

api_ok([
    'messages' => $formatted,
    'me' => $me,
    'is_typing' => $is_typing 
]);