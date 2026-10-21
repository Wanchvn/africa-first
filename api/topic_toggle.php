<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$user = api_require_admin($pdo); 
$user_id = (int)$user['id'];

$input = json_decode(file_get_contents('php://input'), true);
$topic_name = trim($input['topic_name'] ?? '');

if (empty($topic_name)) {
    echo json_encode(['success' => false, 'error' => 'Topic name required']);
    exit;
}

try {
    // 1. Get Topic ID
    $stmt = $pdo->prepare("SELECT id FROM topics WHERE name = :name");
    $stmt->execute([':name' => $topic_name]);
    $topic_id = $stmt->fetchColumn();

    if (!$topic_id) {
        echo json_encode(['success' => false, 'error' => 'Topic not found']);
        exit;
    }

    // 2. Check if already a member
    $stmt = $pdo->prepare("SELECT 1 FROM topic_members WHERE topic_id = :tid AND user_id = :uid");
    $stmt->execute([':tid' => $topic_id, ':uid' => $user_id]);
    $is_member = (bool)$stmt->fetchColumn();

    if ($is_member) {
        // Leave topic
        $stmt = $pdo->prepare("DELETE FROM topic_members WHERE topic_id = :tid AND user_id = :uid");
        $stmt->execute([':tid' => $topic_id, ':uid' => $user_id]);
        $state = 'left';
    } else {
        // Join topic
        $stmt = $pdo->prepare("INSERT INTO topic_members (topic_id, user_id) VALUES (:tid, :uid)");
        $stmt->execute([':tid' => $topic_id, ':uid' => $user_id]);
        $state = 'joined';
    }

    echo json_encode(['success' => true, 'state' => $state]);

} catch (Throwable $e) {
    error_log('Topic toggle error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}