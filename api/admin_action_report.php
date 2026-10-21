<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/crypto.php'; // Needed if messages are encrypted

$user = api_require_admin($pdo);
if (!$user['is_admin']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$admin_id = (int)$user['id'];

// Read the JSON sent from React Native
$input = json_decode(file_get_contents('php://input'), true);
$report_id = (int)($input['report_id'] ?? 0);
$action    = $input['admin_action'] ?? '';

if ($report_id <= 0 || !in_array($action, ['dismiss', 'remove'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT post_id, message_id FROM reports WHERE id = :id");
    $stmt->execute([':id' => $report_id]);
    $report = $stmt->fetch();

    if (!$report) {
        echo json_encode(['success' => false, 'error' => 'Report not found']);
        exit;
    }

    if ($action === 'remove') {
        // Remove Post
        if (!empty($report['post_id'])) {
            // You should also delete media files here like you did in your web version
            $pdo->prepare("DELETE FROM posts WHERE id = :id")->execute([':id' => $report['post_id']]);
        }
        // Remove Message
        if (!empty($report['message_id'])) {
            $pdo->prepare("DELETE FROM messages WHERE id = :id")->execute([':id' => $report['message_id']]);
        }
    }

    // Update report status
    $new_status = $action === 'remove' ? 'actioned' : 'dismissed';
    $stmt = $pdo->prepare("UPDATE reports SET status = :status WHERE id = :id");
    $stmt->execute([':status' => $new_status, ':id' => $report_id]);

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Action failed: ' . $e->getMessage()]);
}