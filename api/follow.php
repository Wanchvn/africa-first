<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/blocks.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error(405, 'Method not allowed.');

$user = api_require_auth($pdo);
$me = (int)$user['id'];

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$target_id = (int)($data['target_id'] ?? 0);

if ($target_id <= 0 || $target_id === $me) api_error(400, 'Invalid target.');

if (is_blocked_either($pdo, $me, $target_id)) {
    api_error(403, 'Cannot follow this user.');
}

$stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = :me AND following_id = :them");
$stmt->execute([':me' => $me, ':them' => $target_id]);
$is_following = (bool)$stmt->fetchColumn();

if ($is_following) {
    $pdo->prepare("DELETE FROM follows WHERE follower_id = :me AND following_id = :them")
        ->execute([':me' => $me, ':them' => $target_id]);
    $state = 'unfollowed';
} else {
    // Added created_at just in case your table needs it
    $pdo->prepare("INSERT INTO follows (follower_id, following_id, created_at) VALUES (:me, :them, NOW())")
        ->execute([':me' => $me, ':them' => $target_id]);
    $state = 'followed';
    
    try {
        $pdo->prepare("INSERT INTO notifications (user_id, actor_id, type, created_at) VALUES (:them, :me, 'follow', NOW())")
            ->execute([':them' => $target_id, ':me' => $me]);
    } catch (Throwable $e) {
        // Ignore notification errors
    }
}

api_ok(['state' => $state]);