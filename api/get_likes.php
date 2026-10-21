<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$user = api_require_admin($pdo); 
$user_id = (int)$user['id'];

$post_id = (int)($_GET['post_id'] ?? 0);

if ($post_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid post ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            u.id, u.username, u.display_name AS name, u.avatar,
            (SELECT COUNT(*) FROM follows f WHERE f.follower_id = :me AND f.following_id = u.id) AS is_following
        FROM likes l
        INNER JOIN users u ON l.user_id = u.id
        WHERE l.post_id = :post_id
        ORDER BY l.created_at DESC
    ");
    
    $stmt->execute([':me' => $user_id, ':post_id' => $post_id]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format for React Native
    $formattedUsers = array_map(function($u) use ($user_id) {
        return [
            'id' => (int)$u['id'],
            'username' => $u['username'],
            'name' => $u['display_name'] ?: $u['username'],
            'avatar' => $u['avatar'],
            'is_following' => (bool)$u['is_following'],
            'is_me' => ((int)$u['id'] === $user_id)
        ];
    }, $users);

    echo json_encode(['success' => true, 'users' => $formattedUsers]);

} catch (Throwable $e) {
    error_log('Get likes error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}