<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require_once __DIR__ . '/includes/posts.php';
require_once __DIR__ . '/includes/blocks.php';

$viewer_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$post_id   = (int)($_GET['id'] ?? 0);
if ($post_id <= 0) { http_response_code(400); exit; }

$stmt = $pdo->prepare("
    SELECT id, user_id, media_type, video_thumb, visibility
    FROM posts WHERE id = :id LIMIT 1
");
$stmt->execute([':id' => $post_id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post || $post['media_type'] !== 'video' || empty($post['video_thumb'])) {
    http_response_code(404); exit;
}
if (!can_view_post($pdo, $post, $viewer_id)) {
    http_response_code(403); exit;
}

$rel = (string)$post['video_thumb'];
if (!preg_match('#^uploads/(posts_video/|private_posts/)[A-Za-z0-9/._-]+$#', $rel)) {
    http_response_code(404); exit;
}

$root = __DIR__;
$abs  = realpath($root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel));
$uploadRoot = realpath($root . '/uploads');

if (!$abs || !$uploadRoot || strpos($abs, $uploadRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)) {
    http_response_code(404); exit;
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($abs));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($abs);