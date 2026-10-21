<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error(405, 'Method not allowed.');
}

$auth_user = api_require_auth($pdo);
$user_id = (int)$auth_user['id'];

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data) || !isset($data['enabled'])) {
    api_error(400, 'Invalid request body.');
}

$enabled = $data['enabled'] ? 1 : 0;

try {
    // If disabling, we also wipe the secret key for security
    $stmt = $pdo->prepare("UPDATE users SET two_factor_enabled = ?, two_factor_secret = IF(?, two_factor_secret, NULL) WHERE id = ?");
    $stmt->execute([$enabled, $enabled, $user_id]);

    api_ok([
        'success' => true,
        'message' => $enabled ? '2FA enabled.' : '2FA disabled.'
    ]);

} catch (Throwable $e) {
    api_error(500, 'Database error: ' . $e->getMessage());
}