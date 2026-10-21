<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$user = api_require_admin($pdo); 
$user_id = (int)$user['id'];

$input = json_decode(file_get_contents('php://input'), true);
$post_id = (int)($input['post_id'] ?? 0);
$content = trim($input['content'] ?? '');

if ($post_id <= 0 || empty($content)) {
    echo json_encode(['success' => false, 'error' => 'Invalid post ID or empty content.']);
    exit;
}

try {
    // 1. Verify the user owns the post
    $stmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = :id");
    $stmt->execute([':id' => $post_id]);
    $author_id = $stmt->fetchColumn();

    if (!$author_id) {
        echo json_encode(['success' => false, 'error' => 'Post not found.']);
        exit;
    }

    if ($author_id !== $user_id) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized. You can only edit your own posts.']);
        exit;
    }

    // 2. Update the post and set edited_at timestamp
    $stmt = $pdo->prepare("UPDATE posts SET content = :content, edited_at = NOW() WHERE id = :id");
    $stmt->execute([':content' => $content, ':id' => $post_id]);

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    error_log('Edit post error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}