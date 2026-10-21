<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require_once __DIR__ . '/includes/dating.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}

$me    = (int)$_SESSION['user_id'];
$photo = (int)($_GET['id'] ?? 0);
if ($photo <= 0) {
    http_response_code(404);
    exit;
}

// Load the photo row.
$stmt = $pdo->prepare("
    SELECT dp.user_id, dp.path, u.is_banned
    FROM dating_photos dp
    INNER JOIN users u ON u.id = dp.user_id
    WHERE dp.id = :id LIMIT 1
");
$stmt->execute([':id' => $photo]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    exit;
}

// Access control: viewer must be an active dating member, OR be the owner.
$isOwner = (int)$row['user_id'] === $me;
if (!$isOwner && !is_dating_active($pdo, $me)) {
    http_response_code(403);
    exit;
}

if ((int)$row['is_banned'] === 1 && !$isOwner) {
    http_response_code(404);
    exit;
}

// Block check for non-owners.
if (!$isOwner) {
    $b = $pdo->prepare("
        SELECT 1 FROM user_blocks
        WHERE (blocker_id = :v AND blocked_id = :t)
           OR (blocker_id = :t AND blocked_id = :v)
        LIMIT 1
    ");
    $b->execute([':v' => $me, ':t' => (int)$row['user_id']]);
    if ($b->fetchColumn()) {
        http_response_code(404);
        exit;
    }
}

$abs = __DIR__ . '/' . $row['path'];
if (!is_file($abs)) {
    http_response_code(404);
    exit;
}

// Serve with a long cache header — photos are immutable.
$mime = function_exists('mime_content_type') ? mime_content_type($abs) : 'image/jpeg';
if (!$mime) $mime = 'image/jpeg';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Cache-Control: private, max-age=86400');
readfile($abs);
exit;