<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$user = api_require_admin($pdo); 
$user_id = (int)$user['id'];

$input = json_decode(file_get_contents('php://input'), true);
$comment_id = (int)($input['comment_id'] ?? 0);

if ($comment_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid comment ID']);
    exit;
}

try {
    // 1. Verify the user owns the comment
    $stmt = $pdo->prepare("SELECT user_id FROM comments WHERE id = :id");
    $stmt->execute([':id' => $comment_id]);
    $author_id = $stmt->fetchColumn();

    if (!$author_id) {
        echo json_encode(['success' => false, 'error' => 'Comment not found.']);
        exit;
    }

    if ($author_id !== $user_id) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized. You can only delete your own comments.']);
        exit;
    }

    // 2. Delete the comment
    $stmt = $pdo->prepare("DELETE FROM comments WHERE id = :id");
    $stmt->execute([':id' => $comment_id]);

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    error_log('Delete comment error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}