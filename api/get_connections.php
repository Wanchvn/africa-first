<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';

$user = api_require_auth($pdo); 
$me = (int)$user['id'];

$target_username = $_GET['username'] ?? $user['username'];
$type = $_GET['type'] ?? 'followers'; // 'followers' or 'following'

// 1. Get the target user's ID
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
$stmt->execute([':u' => $target_username]);
$target_id = $stmt->fetchColumn();

if (!$target_id) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

try {
    if ($type === 'followers') {
        // People who follow the target
        $sql = "
            SELECT u.id, u.username, u.display_name AS name, u.avatar,
                   (SELECT COUNT(*) FROM follows f2 WHERE f2.follower_id = :me AND f2.following_id = u.id) AS is_following
            FROM follows f
            INNER JOIN users u ON f.follower_id = u.id
            WHERE f.following_id = :target_id
              AND u.id NOT IN (
                  SELECT blocked_id FROM user_blocks WHERE blocker_id = :me_block1
                  UNION
                  SELECT blocker_id FROM user_blocks WHERE blocked_id = :me_block2
              )
            ORDER BY f.created_at DESC
        ";
    } else {
        // People the target is following
        $sql = "
            SELECT u.id, u.username, u.display_name AS name, u.avatar,
                   (SELECT COUNT(*) FROM follows f2 WHERE f2.follower_id = :me AND f2.following_id = u.id) AS is_following
            FROM follows f
            INNER JOIN users u ON f.following_id = u.id
            WHERE f.follower_id = :target_id
              AND u.id NOT IN (
                  SELECT blocked_id FROM user_blocks WHERE blocker_id = :me_block1
                  UNION
                  SELECT blocker_id FROM user_blocks WHERE blocked_id = :me_block2
              )
            ORDER BY f.created_at DESC
        ";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':me' => $me, 
        ':target_id' => $target_id,
        ':me_block1' => $me,
        ':me_block2' => $me
    ]);
    $rawUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $users = [];
    foreach ($rawUsers as $u) {
        $users[] = [
            'id' => (int)$u['id'],
            'username' => $u['username'],
            'name' => $u['display_name'] ?: $u['username'],
            'avatar' => $u['avatar'],
            'is_following' => (bool)$u['is_following'],
            'is_me' => ((int)$u['id'] === $me)
        ];
    }

    echo json_encode(['success' => true, 'users' => $users]);

} catch (Throwable $e) {
    error_log('Get connections error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}