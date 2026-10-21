<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

// Ensure user is logged in and is an admin
$user = api_require_admin($pdo); // Assuming you have this helper in your auth.php
if (!$user['is_admin']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    // 1. Fetch Post Reports
    $stmt = $pdo->prepare("
        SELECT 
            r.id, r.reason, r.details, r.created_at,
            reporter.username AS reporter_username,
            reporter.display_name AS reporter_display_name,
            p.id AS post_id, p.content AS post_content, p.media_path AS post_media,
            author.username AS author_username,
            author.display_name AS author_display_name
        FROM reports r
        INNER JOIN users reporter ON r.reporter_id = reporter.id
        LEFT JOIN posts p ON r.post_id = p.id
        LEFT JOIN users author ON p.user_id = author.id
        WHERE r.status = 'pending' AND r.conversation_id IS NULL
        ORDER BY r.created_at ASC
    ");
    $stmt->execute();
    $post_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch DM Reports
    $stmt = $pdo->prepare("
        SELECT 
            r.id, r.reason, r.details, r.created_at, r.conversation_id, r.message_id,
            reporter.username AS reporter_username,
            reporter.display_name AS reporter_display_name,
            m.body AS message_body,
            sender.username AS sender_username
        FROM reports r
        INNER JOIN users reporter ON r.reporter_id = reporter.id
        LEFT JOIN messages m ON r.message_id = m.id
        LEFT JOIN users sender ON m.sender_id = sender.id
        WHERE r.status = 'pending' AND r.conversation_id IS NOT NULL
        ORDER BY r.created_at ASC
    ");
    $stmt->execute();
    $dm_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Note: If you encrypt messages in your DB, decrypt them here!
    // foreach ($dm_reports as &$r) {
    //    if (!empty($r['message_body'])) $r['message_body'] = crypto_decrypt($r['message_body']);
    // }

    echo json_encode([
        'success' => true,
        'post_reports' => $post_reports,
        'dm_reports' => $dm_reports
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}