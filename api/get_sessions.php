<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';

$user = api_require_auth($pdo);
$me = (int)$user['id'];

// Get the current token being used to make this request
$headers = getallheaders();
$auth_header = $headers['Authorization'] ?? '';
$current_token = str_replace('Bearer ', '', $auth_header);

try {
    $stmt = $pdo->prepare("
        SELECT id, device_name, ip_address, last_active, token 
        FROM user_sessions 
        WHERE user_id = :me 
        ORDER BY last_active DESC
    ");
    $stmt->execute([':me' => $me]);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted = [];
    foreach ($sessions as $s) {
        $formatted[] = [
            'id' => (int)$s['id'],
            'device_name' => $s['device_name'] ?: 'Unknown Device',
            'ip_address' => $s['ip_address'] ?: 'Unknown IP',
            'last_active' => $s['last_active'],
            // Check if this session's token matches the one making the request
            'is_current' => ($s['token'] === $current_token)
        ];
    }

    echo json_encode(['success' => true, 'sessions' => $formatted]);

} catch (Throwable $e) {
    error_log('Get sessions error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}