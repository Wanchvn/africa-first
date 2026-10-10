<?php
/**
 * Qarota — soft-delete a message. Only the sender can delete their own.
 */
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

csrf_verify();

try {
    rate_limit_enforce($pdo, client_ip(), 'message_delete', 30, 60);
} catch (Throwable $e) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests. Slow down.']);
    exit;
}

$me        = (int)$_SESSION['user_id'];
$messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;

if ($messageId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid message ID.']);
    exit;
}

try {
    $ok = delete_message($pdo, $messageId, $me);
    if (!$ok) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => __('message_delete_not_allowed')]);
        exit;
    }

    echo json_encode(['success' => true, 'message_id' => $messageId]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
}