<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';

$user = api_require_auth($pdo);
$me = (int)$user['id'];

$input = json_decode(file_get_contents('php://input'), true);
$post_id = (int)($input['post_id'] ?? 0);
$content = trim($input['content'] ?? '');

if ($post_id <= 0 || empty($content)) {
    echo json_encode(['success' => false, 'error' => 'Invalid comment.']);
    exit;
}

try {
    // 1. Get post author and check if post exists
    $stmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $post_id]);
    $author_id = $stmt->fetchColumn();

    if (!$author_id) {
        echo json_encode(['success' => false, 'error' => 'Post not found.']);
        exit;
    }

    // 2. Insert comment
    $pdo->prepare("INSERT INTO comments (post_id, user_id, content, created_at) VALUES (:p, :u, :c, NOW())")
        ->execute([':p' => $post_id, ':u' => $me, ':c' => $content]);
    $comment_id = (int)$pdo->lastInsertId();

    // 3. Create Notification with preview text
    if ((int)$author_id !== $me) {
        // Grab first 50 characters of the comment
        $preview = mb_substr($content, 0, 50) . (mb_strlen($content) > 50 ? '...' : '');

         $pdo->prepare("
    INSERT INTO notifications (user_id, actor_id, type, post_id, preview_text, is_read, created_at) 
    VALUES (:author, :actor, 'comment', :post, :preview, 0, NOW())
")->execute([
    ':author' => $author_id, 
    ':actor' => $me, 
    ':post' => $post_id,
    ':preview' => $preview
]);
    }

    // 4. Fetch the newly created comment to return to the app
    $stmt = $pdo->prepare("
        SELECT c.id, c.content, c.created_at, u.id AS author_id, u.username, u.display_name AS name, u.avatar
        FROM comments c
        INNER JOIN users u ON c.user_id = u.id
        WHERE c.id = :cid
    ");
    $stmt->execute([':cid' => $comment_id]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);

    $comment = [
        'id' => (int)$c['id'],
        'content' => $c['content'],
        'created_at' => $c['created_at'],
        'author' => [
            'id' => (int)$c['author_id'],
            'name' => $c['name'] ?: $c['username'],
            'username' => $c['username'],
            'avatar' => $c['avatar'],
            'is_me' => true
        ]
    ];

    echo json_encode(['success' => true, 'comment' => $comment]);

} catch (Throwable $e) {
    error_log('Comment error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error.']);
}