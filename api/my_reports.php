<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$user = api_require_admin($pdo); 
$me = (int)$user['id'];

try {
    // Fetch reports submitted by this user
    $stmt = $pdo->prepare("
        SELECT 
            r.id, 
            r.reason, 
            r.status, 
            r.created_at,
            p.content AS post_content
        FROM reports r
        LEFT JOIN posts p ON r.post_id = p.id
        WHERE r.reporter_id = :me
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([':me' => $me]);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'reports' => $reports
    ]);

} catch (Throwable $e) {
    error_log('My reports error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}