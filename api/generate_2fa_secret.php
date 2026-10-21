<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/GoogleAuthenticator.php'; // Include the library you provided

header('Content-Type: application/json; charset=utf-8');

$auth_user = api_require_auth($pdo);
$user_id = (int)$auth_user['id'];

try {
    $ga = new PHPGangsta_GoogleAuthenticator();
    $secret = $ga->createSecret();

    // Save the secret to the DB (but leave two_factor_enabled as 0 until they verify it)
    $stmt = $pdo->prepare("UPDATE users SET two_factor_secret = ?, two_factor_enabled = 0 WHERE id = ?");
    $stmt->execute([$secret, $user_id]);

    // Use the username for the QR code label
    $username = $auth_user['username'];
    $qrCodeUrl = $ga->getQRCodeGoogleUrl('Qarota:' . $username, $secret, 'Qarota');

    api_ok([
        'success' => true,
        'secret' => $secret,
        'qr_uri' => $qrCodeUrl
    ]);

} catch (Throwable $e) {
    api_error(500, 'Server Error: ' . $e->getMessage());
}