<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';

// FIXED: Changed from api_require_admin to api_require_auth
$user = api_require_auth($pdo);
$me = (int)$user['id'];

$query = trim($_GET['q'] ?? '');

if (mb_strlen($query) < 2) {
    api_ok(['users' => [], 'posts' => []]);
}

// 1. Search users
$stmt = $pdo->prepare("
    SELECT id, username, display_name, avatar, verification_type
    FROM users
    WHERE (username LIKE :q OR display_name LIKE :q)
      AND id != :me
      AND is_banned = 0
      -- NEW: EXCLUDE BLOCKED USERS
      AND id NOT IN (
          SELECT blocked_id FROM user_blocks WHERE blocker_id = :me_block1
          UNION
          SELECT blocker_id FROM user_blocks WHERE blocked_id = :me_block2
      )
    ORDER BY username ASC
    LIMIT 15
");
$stmt->execute([
    ':q' => '%' . $query . '%', 
    ':me' => $me,
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
        'is_verified' => ($u['verification_type'] ?? 'none') !== 'none'
    ];
}

// 2. Search posts
$stmt = $pdo->prepare("
    SELECT posts.id, posts.content, posts.media_path, posts.created_at, posts.edited_at, posts.media_type,
           posts.media_duration, posts.video_thumb,
           u.id AS author_id, u.username, u.display_name, u.avatar, u.verification_type,
           (SELECT COUNT(*) FROM likes WHERE post_id = posts.id) AS like_count,
           (SELECT COUNT(*) FROM likes WHERE post_id = posts.id AND user_id = :me_like) AS liked_by_me,
           (SELECT COUNT(*) FROM comments WHERE post_id = posts.id) AS comment_count,
           (SELECT COUNT(*) FROM bookmarks WHERE post_id = posts.id AND user_id = :me_bookmark) AS is_bookmarked,
           (SELECT GROUP_CONCAT(t.name SEPARATOR ',') FROM post_topics pt INNER JOIN topics t ON pt.topic_id = t.id WHERE pt.post_id = posts.id) AS topics
    FROM posts
    INNER JOIN users u ON u.id = posts.user_id
    WHERE posts.content LIKE :q
      AND u.is_banned = 0
      -- NEW: EXCLUDE BLOCKED USERS
      AND posts.user_id NOT IN (
          SELECT blocked_id FROM user_blocks WHERE blocker_id = :me_block1_post
          UNION
          SELECT blocker_id FROM user_blocks WHERE blocked_id = :me_block2_post
      )
    ORDER BY posts.created_at DESC
    LIMIT 15
");
$stmt->execute([
    ':q' => '%' . $query . '%', 
    ':me_like' => $me,
    ':me_bookmark' => $me,
    ':me_block1_post' => $me,
    ':me_block2_post' => $me
]);
$rawPosts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$posts = [];
foreach ($rawPosts as $p) {
    $posts[] = [
        'id' => (int)$p['id'],
        'content' => $p['content'],
        'created_at' => $p['created_at'],
        'edited_at' => $p['edited_at'] ?? null,
        'media_type' => $p['media_type'] ?: 'text',
        'media_path' => $p['media_path'],
        'media_duration' => (int)($p['media_duration'] ?? 0),
        'video_thumb' => $p['video_thumb'] ?? null,
        'topics' => $p['topics'] ? explode(',', $p['topics']) : [],
        'author' => [
            'id' => (int)$p['author_id'],
            'name' => $p['display_name'] ?: $p['username'],
            'username' => $p['username'],
            'avatar' => $p['avatar'],
            'is_verified' => ($p['verification_type'] ?? 'none') !== 'none'
        ],
        'stats' => [
            'likes' => (int)$p['like_count'],
            'comments' => (int)$p['comment_count'],
            'liked_by_me' => (bool)$p['liked_by_me']
        ],
        'is_bookmarked' => (bool)$p['is_bookmarked'],
        'is_mine' => ((int)$p['author_id'] === $me)
    ];
}

api_ok(['users' => $users, 'posts' => $posts]);