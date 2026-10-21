<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$user = api_require_admin($pdo); 
$user_id = (int)$user['id'];

$input = json_decode(file_get_contents('php://input'), true);
$post_id = (int)($input['post_id'] ?? 0);

if ($post_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid post ID']);
    exit;
}

try {
    // Check if the bookmark already exists
    $stmt = $pdo->prepare("SELECT id FROM bookmarks WHERE user_id = :uid AND post_id = :pid");
    $stmt->execute([':uid' => $user_id, ':pid' => $post_id]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        // Remove bookmark
        $stmt = $pdo->prepare("DELETE FROM bookmarks WHERE user_id = :uid AND post_id = :pid");
        $stmt->execute([':uid' => $user_id, ':pid' => $post_id]);
        $state = 'unbookmarked';
    } else {
        // Add bookmark
        $stmt = $pdo->prepare("INSERT INTO bookmarks (user_id, post_id, created_at) VALUES (:uid, :pid, NOW())");
        $stmt->execute([':uid' => $user_id, ':pid' => $post_id]);
        $state = 'bookmarked';
    }

    echo json_encode(['success' => true, 'state' => $state]);

} catch (Throwable $e) {
    error_log('Bookmark error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}