<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

csrf_verify();
rate_limit_enforce($pdo, client_ip(), 'edit_comment', 30, 300);

$user_id = (int)$_SESSION['user_id'];
$comment_id = (int)($_POST['comment_id'] ?? 0);
$content = trim($_POST['content'] ?? '');

if ($comment_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid comment ID']);
    exit;
}

if ($content === '') {
    echo json_encode(['success' => false, 'error' => 'Comment cannot be empty']);
    exit;
}

if (mb_strlen($content) > 500) {
    echo json_encode(['success' => false, 'error' => 'Comment must be 500 characters or less']);
    exit;
}

// Fetch the comment and verify ownership
$stmt = $pdo->prepare("SELECT user_id, created_at FROM comments WHERE id = :id");
$stmt->execute([':id' => $comment_id]);
$comment = $stmt->fetch();

if (!$comment) {
    echo json_encode(['success' => false, 'error' => 'Comment not found']);
    exit;
}

if ((int)$comment['user_id'] !== $user_id) {
    echo json_encode(['success' => false, 'error' => 'Not your comment']);
    exit;
}

// Don't allow editing comments older than 30 days
$thirty_days_ago = strtotime('-30 days');
if (strtotime($comment['created_at']) < $thirty_days_ago) {
    echo json_encode(['success' => false, 'error' => 'Comments older than 30 days cannot be edited']);
    exit;
}

// Update
$stmt = $pdo->prepare("
    UPDATE comments
    SET content = :content, edited_at = NOW()
    WHERE id = :id AND user_id = :user_id
");
$stmt->execute([
    ':content' => $content,
    ':id'      => $comment_id,
    ':user_id' => $user_id,
]);

echo json_encode([
    'success' => true,
    'content' => $content,
    'edited_at' => date('Y-m-d H:i:s'),
]);