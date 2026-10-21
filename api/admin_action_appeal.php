<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$user = api_require_admin($pdo); 

// Check if admin
if (!isset($user['is_admin']) || !$user['is_admin']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$admin_id = (int)$user['id'];

// Read JSON payload from React Native
$input = json_decode(file_get_contents('php://input'), true);
$appeal_id = (int)($input['appeal_id'] ?? 0);
$action    = $input['action'] ?? ''; // 'approve' or 'reject'

if ($appeal_id <= 0 || !in_array($action, ['approve', 'reject'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

try {
    // 1. Get the user_id associated with this appeal
    $stmt = $pdo->prepare("SELECT user_id FROM appeals WHERE id = :id AND status = 'pending'");
    $stmt->execute([':id' => $appeal_id]);
    $user_id = $stmt->fetchColumn();

    if (!$user_id) {
        echo json_encode(['success' => false, 'error' => 'Appeal not found or already resolved']);
        exit;
    }

    // 2. Update the appeal status and admin details
    $new_status = $action === 'approve' ? 'approved' : 'rejected';
    $stmt = $pdo->prepare("
        UPDATE appeals 
        SET status = :status, 
            admin_id = :admin_id, 
            admin_reply = :reply,
            resolved_at = NOW() 
        WHERE id = :id
    ");
    $stmt->execute([
        ':status' => $new_status,
        ':admin_id' => $admin_id,
        ':reply' => $action === 'approve' ? 'Appeal approved. Welcome back.' : 'Appeal rejected.',
        ':id' => $appeal_id
    ]);

    // 3. If approved, unban the user in the users table
    if ($action === 'approve') {
        // Assuming your users table uses 'is_banned' (1 or 0)
        $stmt = $pdo->prepare("UPDATE users SET is_banned = 0, ban_reason = NULL WHERE id = :uid");
        $stmt->execute([':uid' => $user_id]);

        // Log to moderation log
        $pdo->prepare("
            INSERT INTO moderation_log (action_type, policy_category, admin_id)
            VALUES ('user_unbanned', 'appeal_approved', :admin)
        ")->execute([':admin' => $admin_id]);
    } else {
        // Log rejection
        $pdo->prepare("
            INSERT INTO moderation_log (action_type, policy_category, admin_id)
            VALUES ('appeal_rejected', 'appeal_rejected', :admin)
        ")->execute([':admin' => $admin_id]);
    }

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    error_log('Admin action appeal error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}