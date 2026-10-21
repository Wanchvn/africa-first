<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';

$user = api_require_auth($pdo);
$me = (int)$user['id'];

$input = json_decode(file_get_contents('php://input'), true);
$conversation_id = (int)($input['conversation_id'] ?? 0);
$body = trim($input['body'] ?? '');

if ($conversation_id <= 0 || empty($body)) {
    echo json_encode(['success' => false, 'error' => 'Missing message data.']);
    exit;
}

try {
    // Verify user is in this conversation
    $stmt = $pdo->prepare("SELECT 1 FROM conversation_members WHERE conversation_id = :cid AND user_id = :me");
    $stmt->execute([':cid' => $conversation_id, ':me' => $me]);
    if (!$stmt->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
        exit;
    }

    // Insert the message (FIXED: Added created_at and is_read)
    $stmt = $pdo->prepare("
        INSERT INTO messages (conversation_id, sender_id, body, created_at, is_read) 
        VALUES (:cid, :me, :body, NOW(), 0)
    ");
    $stmt->execute([
        ':cid' => $conversation_id, 
        ':me' => $me, 
        ':body' => $body
    ]);

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    error_log('Send message error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error.']);
}