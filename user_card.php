<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$my_id = (int)$_SESSION['user_id'];
$user_id = (int)($_GET['id'] ?? 0);

if ($user_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
    exit;
}

// Fetch user data
$stmt = $pdo->prepare("
    SELECT
        id, username, display_name, avatar, bio,
        (SELECT COUNT(*) FROM follows WHERE following_id = users.id) AS follower_count,
        (SELECT COUNT(*) FROM follows WHERE follower_id = users.id) AS following_count
    FROM users
    WHERE id = :id
");
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

$display_name = $user['display_name'] ?: $user['username'];

// Check follow status (only if not viewing own card)
$is_following = false;
$is_self = ((int)$user['id'] === $my_id);
if (!$is_self) {
    $stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = :me AND following_id = :them");
    $stmt->execute([':me' => $my_id, ':them' => $user_id]);
    $is_following = (bool)$stmt->fetch();
}

// Truncate bio to 120 chars for the preview
$bio = $user['bio'] ?? '';
if (mb_strlen($bio) > 120) {
    $bio = mb_substr($bio, 0, 120) . '…';
}

echo json_encode([
    'success' => true,
    'user' => [
        'id'              => (int)$user['id'],
        'username'        => $user['username'],
        'display_name'    => $display_name,
        'avatar'          => $user['avatar'],
        'bio'             => $bio,
        'follower_count'  => (int)$user['follower_count'],
        'following_count' => (int)$user['following_count'],
        'is_following'    => $is_following,
        'is_self'         => $is_self,
    ],
]);