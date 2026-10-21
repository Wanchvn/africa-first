<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$user = api_require_admin($pdo); 
$user_id = (int)$user['id'];

try {
    // 1. Fetch Trending Topics (Top 5)
    $stmt = $pdo->prepare("
        SELECT t.id, t.name, COUNT(pt.post_id) AS post_count
        FROM topics t
        INNER JOIN post_topics pt ON t.id = pt.topic_id
        GROUP BY t.id
        ORDER BY post_count DESC
        LIMIT 5
    ");
    $stmt->execute();
    $trending_topics = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch Suggested Users (Not following, not me, limit 5)
    $stmt = $pdo->prepare("
        SELECT id, username, display_name AS name, avatar, verification_type
        FROM users 
        WHERE id != :me 
          AND id NOT IN (SELECT following_id FROM follows WHERE follower_id = :me_follow)
          AND is_banned = 0
        ORDER BY RAND() 
        LIMIT 5
    ");
    $stmt->execute([':me' => $user_id, ':me_follow' => $user_id]);
    $suggested_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Fetch Explore Posts (Latest 20 public posts from people NOT followed, excluding me)
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
        INNER JOIN users ON posts.user_id = users.id
        WHERE posts.user_id != :me_post
          AND posts.user_id NOT IN (SELECT following_id FROM follows WHERE follower_id = :me_follow_post)
          AND posts.visibility = 'public'
        ORDER BY posts.created_at DESC
        LIMIT 20
    ");
    $stmt->execute([
        ':me_like' => $user_id, 
        ':me_bookmark' => $user_id, 
        ':me_post' => $user_id,
        ':me_follow_post' => $user_id
    ]);
    $raw_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $explore_posts = [];
    foreach ($raw_posts as $p) {
        $explore_posts[] = [
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

    echo json_encode([
        'success' => true,
        'trending_topics' => $trending_topics,
        'suggested_users' => $suggested_users,
        'posts' => $explore_posts
    ]);

} catch (Throwable $e) {
    error_log('Explore error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}