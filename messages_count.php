<?php
/**
 * Qarota — returns the current user's unread DM count.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'includes/messages.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}

$me = (int)$_SESSION['user_id'];
$count = unread_message_count($pdo, $me);

echo json_encode(['success' => true, 'count' => $count]);