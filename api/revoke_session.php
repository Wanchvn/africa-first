<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';

$user = api_require_auth($pdo);
$me = (int)$user['id'];

$input = json_decode(file_get_contents('php://input'), true);
$session_id = (int)($input['session_id'] ?? 0);

if ($session_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid session ID.']);
    exit;
}

try {
    // Delete the session ONLY if it belongs to the current user
    $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE id = :id AND user_id = :me");
    $stmt->execute([':id' => $session_id, ':me' => $me]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Session not found or unauthorized.']);
    }

} catch (Throwable $e) {
    error_log('Revoke session error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}