<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';

$user = api_require_auth($pdo);
$me = (int)$user['id'];

try {
    $stmt = $pdo->prepare("
        SELECT 
            n.id, 
            n.type, 
            n.post_id, 
            n.is_read, 
            n.created_at,
            n.preview_text, -- <-- ADDED THIS
            u.id AS actor_id, 
            u.username AS actor_username, 
            u.display_name AS actor_name, 
            u.avatar AS actor_avatar
        FROM notifications n
        INNER JOIN users u ON n.actor_id = u.id
        WHERE n.user_id = :me
        ORDER BY n.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([':me' => $me]);
    $raw_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $notifications = [];
    foreach ($raw_notifications as $n) {
        $notifications[] = [
            'id' => (int)$n['id'],
            'type' => $n['type'],
            'post_id' => $n['post_id'] ? (int)$n['post_id'] : null,
            'is_read' => (bool)$n['is_read'],
            'created_at' => $n['created_at'],
            'preview_text' => $n['preview_text'], // <-- ADDED THIS
            'actor' => [
                'id' => (int)$n['actor_id'],
                'name' => $n['actor_name'] ?: $n['actor_username'],
                'username' => $n['actor_username'],
                'avatar' => $n['actor_avatar']
            ]
        ];
    }

    echo json_encode(['success' => true, 'notifications' => $notifications]);

} catch (Throwable $e) {
    error_log('Get notifications error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}