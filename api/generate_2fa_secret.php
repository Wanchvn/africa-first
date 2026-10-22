<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/QarotaTOTP.php'; // Use our new class

header('Content-Type: application/json; charset=utf-8');

$auth_user = api_require_auth($pdo);
$user_id = (int)$auth_user['id'];

try {
    $totp = new QarotaTOTP();
    $secret = $totp->createSecret();

    // Save the secret to the DB
    $stmt = $pdo->prepare("UPDATE users SET two_factor_secret = ?, two_factor_enabled = 0 WHERE id = ?");
    $stmt->execute([$secret, $user_id]);

    $username = $auth_user['username'];
    
    // Build the URI
    $label = urlencode('Qarota:' . $username);
    $issuer = urlencode('Qarota');
    $qr_uri = "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}";

    api_ok([
        'success' => true,
        'secret' => $secret,
        'qr_uri' => $qr_uri
    ]);

} catch (Throwable $e) {
    api_error(500, 'Server Error: ' . $e->getMessage());
}