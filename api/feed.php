<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/posts.php';
require_once __DIR__ . '/../includes/badge.php';
require_once __DIR__ . '/../includes/language.php';

$user = api_require_auth($pdo);
$user_id = (int)$user['id'];

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 15;
$offset = ($page - 1) * $limit;

// 1. Get the user's feed preference
$stmt = $pdo->prepare("SELECT feed_show_unfollowed, feed_safe_mode FROM users WHERE id = :id");
$stmt->execute([':id' => $user_id]);
$prefs = $stmt->fetch(PDO::FETCH_ASSOC);
$show_unfollowed = (bool)($prefs['feed_show_unfollowed'] ?? false);

// 2. Build the visibility condition dynamically
$feed_condition = "(follows.following_id IS NOT NULL OR posts.user_id = :feed_owner)";
if ($show_unfollowed) {
    // If enabled, also include public posts from anyone
    $feed_condition = "(" . $feed_condition . " OR posts.visibility = 'public')";
}

$stmt = $pdo->prepare("
    SELECT
        posts.id, posts.content, posts.media_path, posts.created_at, posts.edited_at,
        posts.media_type, posts.media_duration, posts.video_thumb,
        users.username, users.avatar, users.display_name, users.verification_type, users.id AS author_id,
        (SELECT COUNT(*) FROM likes WHERE post_id = posts.id) AS like_count,
        (SELECT COUNT(*) FROM likes WHERE post_id = posts.id AND user_id = :me_like) AS liked_by_me,
        (SELECT COUNT(*) FROM comments WHERE post_id = posts.id) AS comment_count,
        (SELECT COUNT(*) FROM bookmarks WHERE post_id = posts.id AND user_id = :me_bookmark) AS is_bookmarked,
        (SELECT GROUP_CONCAT(t.name SEPARATOR ',') FROM post_topics pt INNER JOIN topics t ON pt.topic_id = t.id WHERE pt.post_id = posts.id) AS topics
    FROM posts
    LEFT JOIN follows ON posts.user_id = follows.following_id AND follows.follower_id = :feed_following
    INNER JOIN users ON posts.user_id = users.id
    WHERE $feed_condition
      AND posts.user_id NOT IN (
          SELECT blocked_id FROM user_blocks WHERE blocker_id = :me_block1
          UNION
          SELECT blocker_id FROM user_blocks WHERE blocked_id = :me_block2
      )
    ORDER BY posts.created_at DESC
    LIMIT :limit OFFSET :offset
");

$stmt->bindValue(':feed_following', $user_id, PDO::PARAM_INT);
$stmt->bindValue(':feed_owner', $user_id, PDO::PARAM_INT);
$stmt->bindValue(':me_like', $user_id, PDO::PARAM_INT);
$stmt->bindValue(':me_bookmark', $user_id, PDO::PARAM_INT);
$stmt->bindValue(':me_block1', $user_id, PDO::PARAM_INT);
$stmt->bindValue(':me_block2', $user_id, PDO::PARAM_INT);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$formattedPosts = [];
foreach ($posts as $p) {
    $formattedPosts[] = [
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
        'is_mine' => ((int)$p['author_id'] === $user_id)
    ];
}

api_ok(['posts' => $formattedPosts]);