<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$user = api_require_admin($pdo); 
$user_id = (int)$user['id'];

try {
    // Mark all unread notifications for this user as read
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :me AND is_read = 0");
    $stmt->execute([':me' => $user_id]);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('Mark notifications read error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}