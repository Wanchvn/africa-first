<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require 'includes/messages.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// CSRF — same key name your other forms use
csrf_verify();

try {
    rate_limit_enforce($pdo, client_ip(), 'message', 30, 60);
} catch (Throwable $e) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many messages. Slow down.']);
    exit;
}

$me   = (int) $_SESSION['user_id'];
$conv = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;
$body = isset($_POST['body']) ? (string) $_POST['body'] : '';

if ($conv <= 0 || !user_in_conversation($pdo, $conv, $me)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => __('messages_not_found')]);
    exit;
}

try {
    $id = send_message($pdo, $conv, $me, $body);

    $s = $pdo->prepare(
        "SELECT id, sender_id, body, created_at FROM messages WHERE id = :id LIMIT 1"
    );
    $s->execute([':id' => $id]);
    $msg = $s->fetch();

    echo json_encode(['success' => true, 'message' => $msg]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
}