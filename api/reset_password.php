<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$input = json_decode(file_get_contents('php://input'), true);
$email = trim($input['email'] ?? '');
$token = trim($input['token'] ?? '');
$new_password = $input['new_password'] ?? '';

if (empty($email) || empty($token) || empty($new_password)) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

if (strlen($new_password) < 8) {
    echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters.']);
    exit;
}

try {
    // 1. Verify Token
    $stmt = $pdo->prepare("
        SELECT id FROM password_resets 
        WHERE email = :email AND token = :token AND expires_at > NOW() 
        LIMIT 1
    ");
    $stmt->execute([':email' => $email, ':token' => $token]);
    $reset_id = $stmt->fetchColumn();

    if (!$reset_id) {
        echo json_encode(['success' => false, 'error' => 'Invalid or expired reset code.']);
        exit;
    }

    // 2. Update User Password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password = :password WHERE email = :email");
    $stmt->execute([':password' => $hashed_password, ':email' => $email]);

    // 3. Delete the used token
    $pdo->prepare("DELETE FROM password_resets WHERE email = :email")->execute([':email' => $email]);

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    error_log('Reset password error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}