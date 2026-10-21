<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$user = api_require_admin($pdo); 
$user_id = (int)$user['id'];

try {
    // Fetch topics the user is a member of
    $stmt = $pdo->prepare("
        SELECT t.id, t.name 
        FROM topics t
        INNER JOIN topic_members tm ON t.id = tm.topic_id
        WHERE tm.user_id = :me
        ORDER BY t.name ASC
    ");
    $stmt->execute([':me' => $user_id]);
    $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'topics' => $topics]);

} catch (Throwable $e) {
    error_log('Get my topics error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}