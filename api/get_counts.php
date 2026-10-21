<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php'; // Added this just in case

// FIXED: Changed from api_require_admin to api_require_auth
$user = api_require_auth($pdo); 
$user_id = (int)$user['id'];

try {
    // 1. Unread Messages
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM messages m
        INNER JOIN conversation_members cm ON m.conversation_id = cm.conversation_id
        WHERE cm.user_id = :me 
          AND m.sender_id != :me_sender 
          AND m.is_read = 0
    ");
    $stmt->execute([':me' => $user_id, ':me_sender' => $user_id]);
    $unread_messages = (int)$stmt->fetchColumn();

    // 2. Unread Notifications
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :me AND is_read = 0");
    $stmt->execute([':me' => $user_id]);
    $unread_notifications = (int)$stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'unread_messages' => $unread_messages,
        'unread_notifications' => $unread_notifications
    ]);

} catch (Throwable $e) {
    error_log('Get counts error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}