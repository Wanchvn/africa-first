<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$user = api_require_admin($pdo); 
$user_id = (int)$user['id'];

$input = json_decode(file_get_contents('php://input'), true);

try {
    // Assuming you receive the whole settings object, 
    // update the user record.
    $stmt = $pdo->prepare("
        UPDATE users 
        SET notify_likes = :likes,
            notify_comments = :comments,
            notify_follows = :follows,
            notify_messages = :messages,
            notify_topic_posts = :topic_posts,
            is_private = :is_private
        WHERE id = :id
    ");
    
    $stmt->execute([
        ':likes' => isset($input['notify_likes']) ? (int)$input['notify_likes'] : 1,
        ':comments' => isset($input['notify_comments']) ? (int)$input['notify_comments'] : 1,
        ':follows' => isset($input['notify_follows']) ? (int)$input['notify_follows'] : 1,
        ':messages' => isset($input['notify_messages']) ? (int)$input['notify_messages'] : 1,
        ':topic_posts' => isset($input['notify_topic_posts']) ? (int)$input['notify_topic_posts'] : 1,
        ':is_private' => isset($input['is_private']) ? (int)$input['is_private'] : 0,
        ':id' => $user_id
    ]);

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}