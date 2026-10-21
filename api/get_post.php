<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$user = api_require_admin($pdo); 
$me = (int)$user['id'];
$post_id = (int)($_GET['id'] ?? 0);

if ($post_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid post ID']);
    exit;
}

try {
    // 1. Fetch the Post
    $stmt = $pdo->prepare("
        SELECT 
            p.id, p.content, p.created_at, p.media_type, p.media_path, p.media_duration,
            u.id AS author_id, u.username, u.display_name AS name, u.avatar, u.verification_type,
            (SELECT COUNT(*) FROM likes WHERE post_id = p.id) AS like_count,
            (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = :me_like) AS liked_by_me,
            (SELECT COUNT(*) FROM comments WHERE post_id = p.id) AS comment_count
        FROM posts p
        INNER JOIN users u ON p.user_id = u.id
        WHERE p.id = :id
    ");
    $stmt->execute([':id' => $post_id, ':me_like' => $me]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$p) {
        echo json_encode(['success' => false, 'error' => 'Post not found.']);
        exit;
    }

    $post = [
        'id' => (int)$p['id'],
        'content' => $p['content'],
        'created_at' => $p['created_at'],
        'media_type' => $p['media_type'] ?: 'text',
        'media_path' => $p['media_path'],
        'media_duration' => (int)($p['media_duration'] ?? 0),
        'author' => [
            'id' => (int)$p['author_id'],
            'name' => $p['name'] ?: $p['username'],
            'username' => $p['username'],
            'avatar' => $p['avatar'],
            'is_verified' => ($p['verification_type'] ?? 'none') !== 'none'
        ],
        'stats' => [
            'likes' => (int)$p['like_count'],
            'comments' => (int)$p['comment_count'],
            'liked_by_me' => (bool)$p['liked_by_me']
        ],
        'is_mine' => ((int)$p['author_id'] === $me)
    ];

    // 2. Fetch the Comments
    $stmt = $pdo->prepare("
        SELECT 
            c.id, c.content, c.created_at,
            u.id AS author_id, u.username, u.display_name AS name, u.avatar
        FROM comments c
        INNER JOIN users u ON c.user_id = u.id
        WHERE c.post_id = :post_id
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([':post_id' => $post_id]);
    $raw_comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $comments = [];
    foreach ($raw_comments as $c) {
        $comments[] = [
            'id' => (int)$c['id'],
            'content' => $c['content'],
            'created_at' => $c['created_at'],
            'author' => [
                'id' => (int)$c['author_id'],
                'name' => $c['name'] ?: $c['username'],
                'username' => $c['username'],
                'avatar' => $c['avatar'],
                'is_me' => ((int)$c['author_id'] === $me) // <-- ADDED THIS
            ]
        ];
    }

    echo json_encode([
        'success' => true,
        'post' => $post,
        'comments' => $comments
    ]);

} catch (Throwable $e) {
    error_log('Get post details error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}