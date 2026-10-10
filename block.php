<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require 'includes/blocks.php';

$is_ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

function respond($ajax, $data, $fallback) {
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
    } else {
        header('Location: ' . $fallback);
    }
    exit;
}

if (!isset($_SESSION['user_id'])) {
    respond($is_ajax, ['success' => false, 'error' => 'Not logged in.'], 'login.php');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond($is_ajax, ['success' => false, 'error' => 'Method not allowed.'], 'feed.php');
}

csrf_verify();
rate_limit_enforce($pdo, client_ip(), 'block', 20, 60);

$me     = (int)$_SESSION['user_id'];
$target = isset($_POST['target_id']) ? (int)$_POST['target_id'] : 0;
$action = $_POST['block_action'] ?? 'block';   // 'block' or 'unblock'
$redirect = $_POST['redirect'] ?? 'feed.php';

if ($target <= 0 || $target === $me) {
    respond($is_ajax, ['success' => false, 'error' => 'Invalid target.'], $redirect);
}

// Confirm the target exists
$chk = $pdo->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
$chk->execute([':id' => $target]);
if (!$chk->fetchColumn()) {
    respond($is_ajax, ['success' => false, 'error' => 'User not found.'], $redirect);
}

try {
    if ($action === 'unblock') {
        unblock_user($pdo, $me, $target);
        respond($is_ajax, ['success' => true, 'state' => 'unblocked'], $redirect);
    } else {
        block_user($pdo, $me, $target);
        respond($is_ajax, ['success' => true, 'state' => 'blocked'], $redirect);
    }
} catch (Throwable $e) {
    respond($is_ajax, ['success' => false, 'error' => 'Could not update block.'], $redirect);
}