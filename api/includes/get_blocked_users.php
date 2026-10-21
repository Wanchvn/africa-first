<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$user = api_require_admin($pdo); 
$me = (int)$user['id'];

try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.display_name AS name, u.avatar
        FROM user_blocks b
        INNER JOIN users u ON b.blocked_id = u.id
        WHERE b.blocker_id = :me
        ORDER BY b.created_at DESC
    ");
    $stmt->execute([':me' => $me]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'users' => $users]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}