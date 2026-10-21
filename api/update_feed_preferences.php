<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';

$user = api_require_auth($pdo); 
$user_id = (int)$user['id'];

$input = json_decode(file_get_contents('php://input'), true);

$show_unfollowed = isset($input['show_unfollowed']) && $input['show_unfollowed'] ? 1 : 0;
$high_data = isset($input['high_data']) && $input['high_data'] ? 1 : 0;
$safe_mode = isset($input['safe_mode']) && $input['safe_mode'] ? 1 : 0;

try {
    $stmt = $pdo->prepare("
        UPDATE users 
        SET feed_show_unfollowed = :u, feed_high_data = :h, feed_safe_mode = :s 
        WHERE id = :id
    ");
    $stmt->execute([':u' => $show_unfollowed, ':h' => $high_data, ':s' => $safe_mode, ':id' => $user_id]);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}