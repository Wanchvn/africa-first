<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$user = api_require_admin($pdo); 
$me = (int)$user['id'];

try {
    // Fetch matches where the current user is either user_1 or user_2
    // We join the users table to get the OTHER person's details
    $stmt = $pdo->prepare("
        SELECT 
            m.id AS match_id,
            m.conversation_id,
            u.id AS user_id,
            u.display_name AS name,
            u.avatar,
            u.age,
            (SELECT media_path FROM dating_photos WHERE user_id = u.id ORDER BY is_primary DESC LIMIT 1) AS primary_photo
        FROM dating_matches m
        INNER JOIN users u ON (u.id = IF(m.user_1_id = :me, m.user_2_id, m.user_1_id))
        WHERE m.user_1_id = :me OR m.user_2_id = :me
        ORDER BY m.created_at DESC
    ");
    
    $stmt->execute([':me' => $me]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'matches' => $matches
    ]);

} catch (Throwable $e) {
    error_log('Get dating matches error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}