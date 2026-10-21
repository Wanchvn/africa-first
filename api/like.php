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

if ($post_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid post ID.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $post_id]);
    $author_id = $stmt->fetchColumn();

    if (!$author_id) {
        echo json_encode(['success' => false, 'error' => 'Post not found.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT 1 FROM likes WHERE post_id = :p AND user_id = :u LIMIT 1");
    $stmt->execute([':p' => $post_id, ':u' => $me]);
    $is_liked = (bool)$stmt->fetchColumn();

    if ($is_liked) {
        $pdo->prepare("DELETE FROM likes WHERE post_id = :p AND user_id = :u")->execute([':p' => $post_id, ':u' => $me]);
        $liked = false;
    } else {
        $pdo->prepare("INSERT INTO likes (post_id, user_id, created_at) VALUES (:p, :u, NOW())")->execute([':p' => $post_id, ':u' => $me]);
        $liked = true;

        if ((int)$author_id !== $me) {
            $pdo->prepare("
            INSERT INTO notifications (user_id, actor_id, type, post_id, is_read, created_at) 
            VALUES (:author, :actor, 'like', :post, 0, NOW())
        ")->execute([':author' => $author_id, ':actor' => $me, ':post' => $post_id]);
        }
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = :p");
    $stmt->execute([':p' => $post_id]);
    $new_count = (int)$stmt->fetchColumn();

    echo json_encode(['success' => true, 'liked' => $liked, 'like_count' => $new_count]);

} catch (Throwable $e) {
    error_log('Like error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error.']);
}