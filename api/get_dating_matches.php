<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/dating.php';

$user = api_require_auth($pdo);
$me = (int)$user['id'];

try {
    if (!is_dating_active($pdo, $me) || !is_dating_verified($pdo, $me)) {
        api_error(403, 'Dating verification must be approved before using Dating.');
    }

    $stmt = $pdo->prepare("
        SELECT
            m.id AS match_id,
            m.conversation_id,
            u.id AS user_id,
            COALESCE(NULLIF(u.display_name, ''), u.username) AS name,
            u.avatar,
            dp.age,
            (SELECT path FROM dating_photos
             WHERE user_id = u.id
             ORDER BY position ASC, id ASC
             LIMIT 1) AS primary_photo,
            (SELECT id FROM dating_photos
             WHERE user_id = u.id
             ORDER BY position ASC, id ASC
             LIMIT 1) AS primary_photo_id
        FROM dating_matches m
        INNER JOIN users u
          ON u.id = CASE WHEN m.user_a_id = :me THEN m.user_b_id ELSE m.user_a_id END
        LEFT JOIN dating_profiles dp ON dp.user_id = u.id
        WHERE m.is_active = 1
          AND (m.user_a_id = :me OR m.user_b_id = :me)
        ORDER BY m.matched_at DESC
    ");
    $stmt->execute([':me' => $me]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    api_ok(['matches' => $matches]);

} catch (Throwable $e) {
    error_log('Get dating matches failed: ' . get_class($e));
    api_error(500, 'Database error.');
}