<?php
/**
 * Qarota — returns the current user's unread notification count.
 * Called by interact.js every N seconds to keep the navbar badge fresh.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}

$me = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM notifications
     WHERE user_id = :me AND is_read = 0"
);
$stmt->execute([':me' => $me]);
$count = (int)$stmt->fetchColumn();

echo json_encode(['success' => true, 'count' => $count]);