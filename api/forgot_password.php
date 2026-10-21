<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$input = json_decode(file_get_contents('php://input'), true);
$email = trim($input['email'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Invalid email address']);
    exit;
}

try {
    // 1. Check if user exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $user_id = $stmt->fetchColumn();

    if (!$user_id) {
        // For security, don't reveal if email exists or not. 
        // Just say "If an account exists..."
        echo json_encode(['success' => true, 'message' => 'If an account with that email exists, a reset code has been sent.']);
        exit;
    }

    // 2. Generate a secure 6-digit code
    $token = sprintf("%06d", mt_rand(1, 999999));
    $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));

    // 3. Delete any old tokens for this email
    $pdo->prepare("DELETE FROM password_resets WHERE email = :email")->execute([':email' => $email]);

    // 4. Insert new token
    $stmt = $pdo->prepare("INSERT INTO password_resets (email, token, created_at, expires_at) VALUES (:email, :token, NOW(), :expires)");
    $stmt->execute([':email' => $email, ':token' => $token, ':expires' => $expires_at]);

    // 5. TODO: Send Email Here.
    // For testing, we will log the code to the PHP error log.
    error_log("Password reset code for $email is: $token");

    echo json_encode(['success' => true, 'message' => 'If an account with that email exists, a reset code has been sent.']);

} catch (Throwable $e) {
    error_log('Forgot password error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}