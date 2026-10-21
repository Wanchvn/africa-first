<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/blocks.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error(405, 'Method not allowed.');

$user = api_require_admin($pdo);
$me = (int)$user['id'];

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$target_id = (int)($data['target_id'] ?? 0);
$action = $data['action'] ?? 'block'; // 'block' or 'unblock'

if ($target_id <= 0 || $target_id === $me) api_error(400, 'Invalid user.');

if ($action === 'unblock') {
    unblock_user($pdo, $me, $target_id);
    api_ok(['state' => 'unblocked']);
} else {
    block_user($pdo, $me, $target_id);
    api_ok(['state' => 'blocked']);
}