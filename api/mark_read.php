<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$user = api_require_admin($pdo); 
$user_id = (int)$user['id'];

$input = json_decode(file_get_contents('php://input'), true);
$conversation_id = (int)($input['conversation_id'] ?? 0);

if ($conversation_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid conversation ID']);
    exit;
}

try {
    // 1. SECURITY CHECK: Verify the user is actually a member of this conversation
    $stmt = $pdo->prepare("SELECT 1 FROM conversation_members WHERE conversation_id = :cid AND user_id = :me");
    $stmt->execute([':cid' => $conversation_id, ':me' => $user_id]);
    
    if (!$stmt->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized. You are not in this conversation.']);
        exit;
    }

    // 2. Mark all messages in this conversation as read where the sender is NOT the current user
    $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE conversation_id = :cid AND sender_id != :me");
    $stmt->execute([':cid' => $conversation_id, ':me' => $user_id]);

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    error_log('Mark read error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}