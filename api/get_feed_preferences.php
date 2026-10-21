<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';

$user = api_require_auth($pdo); 
$user_id = (int)$user['id'];

try {
    $stmt = $pdo->prepare("SELECT feed_show_unfollowed, feed_high_data, feed_safe_mode FROM users WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true, 
        'settings' => [
            'show_unfollowed' => (bool)$settings['feed_show_unfollowed'],
            'high_data' => (bool)$settings['feed_high_data'],
            'safe_mode' => (bool)$settings['feed_safe_mode']
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}