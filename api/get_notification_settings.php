<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$user = api_require_admin($pdo); 
$user_id = (int)$user['id'];

try {
    // You can combine these or keep them separate
    $stmt = $pdo->prepare("SELECT notify_likes, notify_comments, notify_follows, notify_messages, notify_topic_posts, is_private FROM users WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    // Convert 1/0 to true/false
    foreach ($settings as $key => $value) {
        $settings[$key] = (bool)$value;
    }

    echo json_encode(['success' => true, 'settings' => $settings]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}