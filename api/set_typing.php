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

if ($conversation_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid conversation.']);
    exit;
}

try {
    // Upsert (Insert or Update) the typing status
    // Note: If your table uses 'created_at' instead of 'updated_at', change the column name here.
    $stmt = $pdo->prepare("
        INSERT INTO typing_indicators (conversation_id, user_id, updated_at) 
        VALUES (:cid, :uid, NOW()) 
        ON DUPLICATE KEY UPDATE updated_at = NOW()
    ");
    $stmt->execute([':cid' => $conversation_id, ':uid' => $me]);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    // If it fails (e.g. missing unique key), try a simple update instead
    try {
        $pdo->prepare("UPDATE typing_indicators SET updated_at = NOW() WHERE conversation_id = :cid AND user_id = :uid")
            ->execute([':cid' => $conversation_id, ':uid' => $me]);
        echo json_encode(['success' => true]);
    } catch (Throwable $e2) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
}