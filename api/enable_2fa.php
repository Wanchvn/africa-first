<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/QarotaTOTP.php'; // Use our new class

header('Content-Type: application/json; charset=utf-8');

// 1. Authenticate user
$auth_user = api_require_auth($pdo);
$user_id = (int)$auth_user['id'];

// 2. Parse JSON Body
$data = json_decode(file_get_contents('php://input'), true);
$code = trim((string)($data['code'] ?? ''));

if (strlen($code) !== 6) {
    api_error(400, 'Invalid code format.');
}

// 3. Fetch the pending secret from the DATABASE
$stmt = $pdo->prepare("SELECT two_factor_secret FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || empty($user['two_factor_secret'])) {
    api_error(400, 'No pending 2FA setup found. Please generate a new QR code.');
}

$secret = $user['two_factor_secret'];

// 4. Verify the code using the new class
$totp = new QarotaTOTP();
$checkResult = $totp->verifyCode($secret, $code, 2); 

if ($checkResult) {
    $stmt = $pdo->prepare("UPDATE users SET two_factor_enabled = 1 WHERE id = ?");
    $success = $stmt->execute([$user_id]);
    
    if ($success) {
        api_ok(['success' => true]);
    } else {
        api_error(500, 'Database error: Could not update user.');
    }
}