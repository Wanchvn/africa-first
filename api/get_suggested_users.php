<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$user = api_require_admin($pdo); 
$user_id = (int)$user['id'];

try {
    // Fetch users you are NOT following, excluding yourself
    $stmt = $pdo->prepare("
        SELECT id, username, display_name AS name, avatar
        FROM users 
        WHERE id != :me 
          AND id NOT IN (SELECT following_id FROM follows WHERE follower_id = :me_follow)
          AND is_banned = 0
        ORDER BY RAND() 
        LIMIT 5
    ");
    $stmt->execute([':me' => $user_id, ':me_follow' => $user_id]);
    $suggested = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'suggested' => $suggested]);

} catch (Throwable $e) {
    error_log('Get suggested users error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}