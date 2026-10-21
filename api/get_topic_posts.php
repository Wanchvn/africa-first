<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$user = api_require_admin($pdo); 
$user_id = (int)$user['id'];

$topic_name = trim($_GET['name'] ?? '');
if (empty($topic_name)) {
    echo json_encode(['success' => false, 'error' => 'Topic name required']);
    exit;
}

try {
    // 1. Get Topic ID and check if user is a member
    $stmt = $pdo->prepare("SELECT id FROM topics WHERE name = :name");
    $stmt->execute([':name' => $topic_name]);
    $topic_id = $stmt->fetchColumn();

    $is_member = false;
    if ($topic_id) {
        $stmt = $pdo->prepare("SELECT 1 FROM topic_members WHERE topic_id = :tid AND user_id = :uid");
        $stmt->execute([':tid' => $topic_id, ':uid' => $user_id]);
        $is_member = (bool)$stmt->fetchColumn();
    }

    // 2. Fetch Posts
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
        INNER JOIN post_topics pt ON posts.id = pt.post_id
        INNER JOIN topics t ON pt.topic_id = t.id
        INNER JOIN users ON posts.user_id = users.id
        WHERE t.name = :topic_name AND posts.visibility = 'public'
        ORDER BY posts.created_at DESC
        LIMIT 50
    ");
    
    $stmt->execute([
        ':me_like' => $user_id,
        ':me_bookmark' => $user_id,
        ':topic_name' => $topic_name
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
            'is_mine' => ((int)$p['author_id'] === $user_id)
        ];
    }

    // 3. Return everything including is_member
    echo json_encode([
        'success' => true, 
        'posts' => $posts,
        'is_member' => $is_member 
    ]);

} catch (Throwable $e) {
    error_log('Topic posts error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}