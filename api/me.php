<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';

// 1. Validate token and get the base user
$auth_user = api_require_auth($pdo);
$user_id = (int)$auth_user['id'];

try {
    // 2. Explicitly fetch the full user data
    $stmt = $pdo->prepare("
        SELECT id, username, display_name, avatar, is_admin, verification_type 
        FROM users 
        WHERE id = :id 
        LIMIT 1
    ");
    $stmt->execute([':id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        api_error(404, 'User not found.');
    }

    // 3. Return the data exactly how the app expects it
    api_ok([
        'user' => [
            'id'                => (int)$user['id'],
            'username'          => $user['username'],
            'display_name'      => $user['display_name'] ?: $user['username'],
            'avatar'            => $user['avatar'],
            'is_admin'          => (bool)$user['is_admin'],
            'verification_type' => $user['verification_type'] ?? 'none',
        ],
    ]);
} catch (Throwable $e) {
    error_log('Me API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}