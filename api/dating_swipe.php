<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/dating.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error(405, 'Method not allowed.');

$user = api_require_auth($pdo);
$me = (int)$user['id'];

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$targetId = (int)($data['target_id'] ?? 0);
$action = (string)($data['action'] ?? '');

if ($targetId <= 0 || $targetId === $me || !in_array($action, ['like', 'pass'], true)) {
    api_error(400, 'Invalid request.');
}

if (!is_dating_active($pdo, $me) || !is_dating_active($pdo, $targetId)) {
    api_error(403, 'Profile not available.');
}
if (!is_dating_verified($pdo, $me)) {
    api_error(403, 'Dating verification must be approved before using Dating.');
}

try {
    $result = record_swipe($pdo, $me, $targetId, $action);
    
    api_ok([
        'matched' => $result['matched'],
        'match' => $result['match'] ? [
            'id' => (int)$result['match']['id'],
            'conversation_id' => isset($result['match']['conversation_id']) ? (int)$result['match']['conversation_id'] : null,
        ] : null
    ]);
} catch (DomainException $e) {
    api_error(403, $e->getMessage());
} catch (RuntimeException $e) {
    api_error(429, $e->getMessage()); // Rate limit reached
} catch (Throwable $e) {
    api_error(500, 'Server error.');
}