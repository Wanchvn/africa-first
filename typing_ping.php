<?php
/**
 * Qarota — typing indicator ping endpoint.
 *
 * Called from interact.js:
 *   - While the user types (throttled to ~1.5s on the client)
 *   - Once with `clear=1` right after a message is sent
 */
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/messages.php';
require 'includes/blocks.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}

csrf_verify();

$me   = (int)$_SESSION['user_id'];
$conv = isset($_POST['conversation_id']) ? (int)$_POST['conversation_id'] : 0;

if ($conv <= 0 || !user_in_conversation($pdo, $conv, $me)) {
    http_response_code(404);
    echo json_encode(['success' => false]);
    exit;
}

// Never signal "typing" to someone in a blocked pair — pointless.
$partnerId = conversation_partner($pdo, $conv, $me);
if ($partnerId && is_blocked_either($pdo, $me, $partnerId)) {
    echo json_encode(['success' => true]);
    exit;
}

// ---- Clear (sent right after a message is sent) ----
if (!empty($_POST['clear'])) {
    clear_typing($pdo, $conv, $me);
    echo json_encode(['success' => true]);
    exit;
}

// ---- Normal typing ping ----
set_typing($pdo, $conv, $me);
echo json_encode(['success' => true]);