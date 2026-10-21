<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$user = api_require_admin($pdo); 

if (!isset($user['is_admin']) || !$user['is_admin']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$type = $_GET['type'] ?? 'profile';
$items = [];

try {
    if ($type === 'profile') {
        // UPDATED: Fetching the exact columns from your screenshot
        $stmt = $pdo->prepare("
            SELECT 
                v.id, 
                v.full_legal_name,
                v.category,
                v.country,
                v.id_type,
                v.id_number,
                v.reason,
                v.id_front_path,
                v.id_back_path,
                u.username, 
                u.display_name AS name
            FROM verification_applications v
            INNER JOIN users u ON v.user_id = u.id
            WHERE v.status = 'pending'
        ");
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } elseif ($type === 'dating') {
        // Assuming your dating_verification table has these standard columns
        $stmt = $pdo->prepare("
            SELECT 
                v.id, 
                v.created_at, 
                v.selfie_url, 
                v.code,
                u.username, 
                u.display_name AS name
            FROM dating_verification v
            INNER JOIN users u ON v.user_id = u.id
            WHERE v.status = 'pending'
        ");
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['success' => true, 'items' => $items]);

} catch (Throwable $e) {
    error_log('Admin get verifications error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}