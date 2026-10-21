<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$user = api_require_admin($pdo); 
$user_id = (int)$user['id'];

$input = json_decode(file_get_contents('php://input'), true);
$password = $input['password'] ?? '';

if (empty($password)) {
    echo json_encode(['success' => false, 'error' => 'Password is required.']);
    exit;
}

try {
    // 1. Verify password before deleting
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $hash = $stmt->fetchColumn();

    if (!password_verify($password, $hash)) {
        echo json_encode(['success' => false, 'error' => 'Incorrect password.']);
        exit;
    }

    // 2. Delete the user. 
    // Assuming your database has ON DELETE CASCADE set up, this will automatically
    // wipe their posts, likes, comments, matches, and messages.
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
    $stmt->execute([':id' => $user_id]);

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    error_log('Delete account error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}