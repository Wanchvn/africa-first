<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/badge.php';
require_once __DIR__ . '/../includes/posts.php';
require_once __DIR__ . '/../includes/blocks.php';

$user = api_require_auth($pdo);
$me = (int)$user['id'];

$view_username = trim($_GET['username'] ?? $user['username']);
$is_own = ($view_username === $user['username']);

$stmt = $pdo->prepare("
    SELECT id, username, display_name, avatar, bio, 
           location, occupation, education, languages, interests, 
           verification_type,
           (SELECT COUNT(*) FROM follows WHERE following_id = users.id) AS follower_count,
           (SELECT COUNT(*) FROM follows WHERE follower_id = users.id) AS following_count
    FROM users WHERE username = :u LIMIT 1
");
$stmt->execute([':u' => $view_username]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) api_error(404, 'User not found.');

$profile_id = (int)$profile['id'];

// Check if either user has blocked the other
$i_blocked_them = is_blocked($pdo, $me, $profile_id);
$they_blocked_me = is_blocked($pdo, $profile_id, $me);
$is_blocked = $i_blocked_them || $they_blocked_me;

// --- NEW: Check if I am following this user ---
$is_following = false;
if (!$is_own) {
    $stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = :me AND following_id = :them");
    $stmt->execute([':me' => $me, ':them' => $profile_id]);
    $is_following = (bool)$stmt->fetchColumn();
}

$posts = [];

// Only fetch posts if they are NOT blocked
if (!$is_blocked) {
    $stmt = $pdo->prepare("
        SELECT posts.id, posts.content, posts.media_path, posts.created_at, posts.edited_at, posts.media_type,
               posts.media_duration, posts.video_thumb,
               (SELECT COUNT(*) FROM likes WHERE post_id = posts.id) AS like_count,
               (SELECT COUNT(*) FROM likes WHERE post_id = posts.id AND user_id = :me_like) AS liked_by_me,
               (SELECT COUNT(*) FROM comments WHERE post_id = posts.id) AS comment_count,
               (SELECT COUNT(*) FROM bookmarks WHERE post_id = posts.id AND user_id = :me_bookmark) AS is_bookmarked,
               (SELECT GROUP_CONCAT(t.name SEPARATOR ',') FROM post_topics pt INNER JOIN topics t ON pt.topic_id = t.id WHERE pt.post_id = posts.id) AS topics
        FROM posts
        WHERE posts.user_id = :u
        ORDER BY posts.created_at DESC LIMIT 50
    ");
    $stmt->execute([':u' => $profile_id, ':me_like' => $me, ':me_bookmark' => $me]);
    $rawPosts = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                'id' => $profile_id,
                'name' => $profile['display_name'] ?: $profile['username'],
                'username' => $profile['username'],
                'avatar' => $profile['avatar'],
                'is_verified' => ($profile['verification_type'] ?? 'none') !== 'none'
            ],
            'stats' => [
                'likes' => (int)$p['like_count'],
                'comments' => (int)$p['comment_count'],
                'liked_by_me' => (bool)$p['liked_by_me']
            ],
            'is_bookmarked' => (bool)$p['is_bookmarked'],
            'is_mine' => ($profile_id === $me)
        ];
    }
}

api_ok([
    'profile' => [
        'id' => $profile_id,
        'username' => $profile['username'],
        'name' => $profile['display_name'] ?: $profile['username'],
        'avatar' => $profile['avatar'],
        'bio' => $profile['bio'],
        'location' => $profile['location'],
        'work' => $profile['occupation'], 
        'education' => $profile['education'],
        'languages' => $profile['languages'],
        'interests' => $profile['interests'],
        'is_verified' => ($profile['verification_type'] ?? 'none') !== 'none',
        'is_blocked' => $is_blocked,
        'i_blocked_them' => $i_blocked_them,
        'is_following' => $is_following, // <-- FIXED: Returned to app
        'stats' => [
            'followers' => (int)$profile['follower_count'],
            'following' => (int)$profile['following_count'],
            'posts' => count($posts)
        ]
    ],
    'posts' => $posts,
    'is_own' => $is_own
]);