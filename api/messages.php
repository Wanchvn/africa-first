<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/crypto.php';

$user = api_require_auth($pdo);
$me = (int)$user['id'];

try {
    // We use GROUP BY to ensure only ONE chat per user is returned
    $stmt = $pdo->prepare("
        SELECT 
            MAX(c.id) AS conversation_id, 
            u.id AS other_user_id, 
            u.username AS other_username, 
            u.display_name AS other_display_name, 
            u.avatar AS other_avatar,
            MAX(c.created_at) AS created_at
        FROM conversations c
        INNER JOIN conversation_members cm1 ON c.id = cm1.conversation_id AND cm1.user_id = :me
        INNER JOIN conversation_members cm2 ON c.id = cm2.conversation_id AND cm2.user_id != :me
        INNER JOIN users u ON cm2.user_id = u.id
        GROUP BY u.id
        ORDER BY created_at DESC
    ");
    $stmt->execute([':me' => $me]);
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted = [];
    foreach ($conversations as $c) {
        $conv_id = (int)$c['conversation_id'];
        
        $stmt_msg = $pdo->prepare("SELECT body, created_at FROM messages WHERE conversation_id = :cid ORDER BY id DESC LIMIT 1");
        $stmt_msg->execute([':cid' => $conv_id]);
        $last_msg = $stmt_msg->fetch(PDO::FETCH_ASSOC);

        $last_message_text = 'No messages yet';
        if ($last_msg) {
            try {
                $last_message_text = crypto_decrypt($last_msg['body']);
            } catch (Throwable $e) {
                $last_message_text = $last_msg['body'];
            }
        }

        $formatted[] = [
            'id' => $conv_id,
            'other_user' => [
                'id' => (int)$c['other_user_id'],
                'name' => $c['other_display_name'] ?: $c['other_username'],
                'username' => $c['other_username'],
                'avatar' => $c['other_avatar']
            ],
            'last_message' => $last_message_text,
            'updated_at' => $last_msg ? $last_msg['created_at'] : $c['created_at']
        ];
    }

    echo json_encode(['success' => true, 'conversations' => $formatted]);

} catch (Throwable $e) {
    error_log('Messages list error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}