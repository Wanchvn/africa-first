<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$user = api_require_admin($pdo); 

// Check if admin
if (!isset($user['is_admin']) || !$user['is_admin']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    // Fetch pending appeals using your exact column names
    $stmt = $pdo->prepare("
        SELECT 
            a.id, 
            a.appeal_text, 
            a.ban_reason,
            a.created_at,
            u.username
        FROM appeals a
        INNER JOIN users u ON a.user_id = u.id
        WHERE a.status = 'pending'
        ORDER BY a.created_at ASC
    ");
    $stmt->execute();
    $appeals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'appeals' => $appeals
    ]);

} catch (Throwable $e) {
    error_log('Admin get appeals error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}