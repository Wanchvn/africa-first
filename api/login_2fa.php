<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/GoogleAuthenticator.php'; // Load the library

header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);
$userId = $data['user_id'] ?? 0;
$code = $data['code'] ?? '';

if (!$userId || !$code) {
    api_error(400, 'Missing user ID or code.');
}

// Fetch the secret
$stmt = $pdo->prepare("SELECT id, username, two_factor_secret FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !$user['two_factor_secret']) {
    api_error(400, '2FA is not set up for this user.');
}

$ga = new PHPGangsta_GoogleAuthenticator();
// 2 means it allows 1 minute of time drift before/after
$isValid = $ga->verifyCode($user['two_factor_secret'], $code, 2);

if ($isValid) {
    // Code is valid! Issue the real token.
    $issued = api_issue_token($pdo, (int)$user['id'], 'mobile-2fa');
    
    api_ok([
        'success' => true,
        'token' => $issued['token'],
        'user'  => [
            'id'       => (int)$user['id'],
            'username' => $user['username'],
        ],
    ]);
} else {
    api_error(400, 'Invalid or expired authenticator code.');
}