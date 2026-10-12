<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require_once __DIR__ . '/includes/posts.php';

$postId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$postId || $postId <= 0) {
    http_response_code(404);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT id, user_id, visibility, media_path
     FROM posts
     WHERE id = :id
     LIMIT 1"
);
$stmt->execute([':id' => $postId]);
$post = $stmt->fetch();

if (!$post || empty($post['media_path'])) {
    http_response_code(404);
    exit;
}

$viewerId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$canView = can_view_post($pdo, $post, $viewerId);

if (!$canView && ($post['visibility'] ?? 'public') !== 'private' && $viewerId !== null) {
    $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $viewerId]);
    $canView = (bool)$stmt->fetchColumn();
}

if (!$canView) {
    http_response_code(404);
    exit;
}

$mediaPath = (string)$post['media_path'];
if (!preg_match(
    '#^uploads/(?:private_posts/)?[a-f0-9]{32}\.(?:jpg|jpeg|png|gif|webp)$#i',
    $mediaPath
)) {
    http_response_code(404);
    exit;
}

$absolutePath = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $mediaPath));
$uploadsRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads');
if (
    $absolutePath === false
    || $uploadsRoot === false
    || !str_starts_with(strtolower($absolutePath), strtolower($uploadsRoot . DIRECTORY_SEPARATOR))
    || !is_file($absolutePath)
) {
    http_response_code(404);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = $finfo ? finfo_file($finfo, $absolutePath) : false;
if ($finfo) {
    finfo_close($finfo);
}

$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($mimeType, $allowedMimeTypes, true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($absolutePath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($absolutePath);
