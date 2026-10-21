<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

// 1. Ensure the user is logged in
$user = api_require_admin($pdo); 
$user_id = (int)$user['id'];

// 2. Read the JSON payload sent from React Native
$input = json_decode(file_get_contents('php://input'), true);
$current_password = $input['current_password'] ?? '';
$new_password = $input['new_password'] ?? '';

// 3. Validate inputs
if (empty($current_password) || empty($new_password)) {
    echo json_encode(['success' => false, 'error' => 'Please provide both current and new passwords.']);
    exit;
}

if (strlen($new_password) < 8) {
    echo json_encode(['success' => false, 'error' => 'New password must be at least 8 characters long.']);
    exit;
}

try {
    // 4. Fetch the current password hash from the database
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $user_id]);
    $user_data = $stmt->fetch();

    if (!$user_data) {
        echo json_encode(['success' => false, 'error' => 'User not found.']);
        exit;
    }

    // 5. Verify the current password matches the hash in the database
    if (!password_verify($current_password, $user_data['password'])) {
        echo json_encode(['success' => false, 'error' => 'Incorrect current password.']);
        exit;
    }

    // 6. Hash the new password and update the database
    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("UPDATE users SET password = :password WHERE id = :id");
    $stmt->execute([
        ':password' => $new_hash,
        ':id' => $user_id
    ]);

    // 7. (Optional) Log the user out of all other active sessions for security
    // $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE user_id = :id");
    // $stmt->execute([':id' => $user_id]);

    // Return success response
    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    error_log('Change password error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error. Please try again later.']);
}