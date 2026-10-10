<?php
/**
 * Polling endpoint: returns new messages since `after` in a conversation.
 * Called by interact.js every ~4 seconds while the user views the thread.
 *
 * Note: this endpoint deliberately does NOT block on user_blocks, because
 * a blocked user should still be able to *read* historical messages in a
 * thread they're already part of. Sends are blocked in message_send.php;
 * reads are allowed here.
 */
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/messages.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}

$me    = (int) $_SESSION['user_id'];
$conv  = isset($_GET['id'])    ? (int) $_GET['id']    : 0;
$after = isset($_GET['after']) ? (int) $_GET['after'] : 0;

// Must be a participant in the conversation
if ($conv <= 0 || !user_in_conversation($pdo, $conv, $me)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Not found.']);
    exit;
}

// Fetch only new messages after the last-known id
$stmt = $pdo->prepare(
    "SELECT id, sender_id, body, created_at, deleted_at
     FROM messages
     WHERE conversation_id = :c AND id > :after
     ORDER BY id ASC
     LIMIT 200"
);
$stmt->execute([':c' => $conv, ':after' => $after]);
$messages = $stmt->fetchAll();

// Mark incoming as read (clears the bell badge while the thread is open)
mark_conversation_read($pdo, $conv, $me);

// Is the partner currently typing?
$partnerId = conversation_partner($pdo, $conv, $me);
$partnerIsTyping = $partnerId ? is_typing($pdo, $conv, $partnerId) : false;

echo json_encode([
    'success'  => true,
    'messages' => $messages,
    'typing'   => $partnerIsTyping,
]);