<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

// Note: No `api_require_auth` here because the moderation log is PUBLIC on your web app!

try {
    $stmt = $pdo->prepare("
        SELECT 
            m.id, 
            m.action_type, 
            m.post_excerpt, 
            m.policy_category, 
            m.created_at,
            u.username AS admin_username
        FROM moderation_log m
        LEFT JOIN users u ON m.admin_id = u.id
        ORDER BY m.created_at DESC
        LIMIT 100
    ");
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'logs' => $logs
    ]);

} catch (Throwable $e) {
    error_log('Moderation log error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}