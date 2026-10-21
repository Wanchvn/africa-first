<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

// Use your exact auth function
$auth_user = api_require_auth($pdo);
$user_id = (int)$auth_user['id'];

try {
    $stmt = $pdo->prepare("SELECT two_factor_enabled FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        api_error(404, 'User not found.');
    }

    api_ok([
        'success' => true,
        'enabled' => (bool)$user['two_factor_enabled']
    ]);

} catch (Throwable $e) {
    api_error(500, 'Database error: ' . $e->getMessage());
}