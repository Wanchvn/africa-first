<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/blocks.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error(405, 'Method not allowed.');

$user = api_require_auth($pdo);
$me = (int)$user['id'];

$input = json_decode(file_get_contents('php://input'), true);
$target_id = (int)($input['target_id'] ?? 0);

if ($target_id <= 0 || $target_id === $me) api_error(400, 'Invalid target.');

if (function_exists('is_blocked_either') && is_blocked_either($pdo, $me, $target_id)) {
    api_error(403, 'Cannot message this user.');
}

try {
    // 1. Check if a conversation already exists
    // Added ORDER BY c.id ASC to always grab the FIRST one created
    $stmt = $pdo->prepare("
        SELECT c.id 
        FROM conversations c
        INNER JOIN conversation_members m1 ON c.id = m1.conversation_id AND m1.user_id = :me
        INNER JOIN conversation_members m2 ON c.id = m2.conversation_id AND m2.user_id = :target
        ORDER BY c.id ASC
        LIMIT 1
    ");
    $stmt->execute([':me' => $me, ':target' => $target_id]);
    $existing_id = $stmt->fetchColumn();

    if ($existing_id) {
        // Return the existing chat
        api_ok(['conversation_id' => (int)$existing_id]);
    } else {
        // Create a new one
        $pdo->prepare("INSERT INTO conversations (created_at) VALUES (NOW())")->execute();
        $new_conv_id = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO conversation_members (conversation_id, user_id) VALUES (:cid, :uid)");
        $stmt->execute([':cid' => $new_conv_id, ':uid' => $me]);
        $stmt->execute([':cid' => $new_conv_id, ':uid' => $target_id]);

        api_ok(['conversation_id' => $new_conv_id]);
    }
} catch (Throwable $e) {
    error_log('Message start error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error.']);
}