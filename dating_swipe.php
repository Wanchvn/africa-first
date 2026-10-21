<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require_once __DIR__ . '/includes/dating.php';
require_once __DIR__ . '/includes/crypto.php';

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
rate_limit_enforce($pdo, client_ip(), 'dating_swipe', 200, 3600);

$me       = (int)$_SESSION['user_id'];
$targetId = (int)($_POST['target_id'] ?? 0);
$action   = (string)($_POST['action'] ?? '');

if ($targetId <= 0 || $targetId === $me) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid target.']);
    exit;
}
if (!in_array($action, ['like', 'pass'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action.']);
    exit;
}

if (!is_dating_active($pdo, $me)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You are not an active dating member.']);
    exit;
}
if (!is_dating_active($pdo, $targetId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'This profile is not available.']);
    exit;
}

try {
    $result = record_swipe($pdo, $me, $targetId, $action);
    echo json_encode([
        'success' => true,
        'matched' => $result['matched'],
        'match'   => $result['match'] ? [
            'id'              => (int)$result['match']['id'],
            'conversation_id' => isset($result['match']['conversation_id']) ? (int)$result['match']['conversation_id'] : null,
        ] : null,
    ]);
} catch (RuntimeException $e) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('dating_swipe error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
}