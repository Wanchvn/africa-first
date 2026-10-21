<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/GoogleAuthenticator.php';

header('Content-Type: application/json; charset=utf-8');

$auth_user = api_require_auth($pdo);
$user_id = (int)$auth_user['id'];

$data = json_decode(file_get_contents('php://input'), true);
$secret = $data['secret'] ?? '';
$code = $data['code'] ?? '';

$ga = new PHPGangsta_GoogleAuthenticator();
$checkResult = $ga->verifyCode($secret, $code, 2); // 2 = 2*30sec clock tolerance

if ($checkResult) {
    $stmt = $pdo->prepare("UPDATE users SET two_factor_enabled = 1, two_factor_secret = ? WHERE id = ?");
    $stmt->execute([$secret, $user_id]);
    api_ok(['success' => true]);
} else {
    api_error(400, 'Invalid 2FA code. Please try again.');
}