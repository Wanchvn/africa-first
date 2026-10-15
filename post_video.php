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
    SELECT id, user_id, media_type, media_path, media_mime, visibility
    FROM posts WHERE id = :id LIMIT 1
");
$stmt->execute([':id' => $post_id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post || $post['media_type'] !== 'video' || empty($post['media_path'])) {
    http_response_code(404); exit;
}
if (!can_view_post($pdo, $post, $viewer_id)) {
    http_response_code(403); exit;
}

$rel = (string)$post['media_path'];
if (!preg_match('#^uploads/(posts_video/|private_posts/)[A-Za-z0-9/._-]+$#', $rel)) {
    http_response_code(404); exit;
}

$root = __DIR__;
$abs  = realpath($root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel));
$uploadRoot = realpath($root . '/uploads');

if (!$abs || !$uploadRoot || strpos($abs, $uploadRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)) {
    http_response_code(404); exit;
}

$mime = $post['media_mime'] ?: 'video/mp4';
$size = filesize($abs);

$range = $_SERVER['HTTP_RANGE'] ?? '';
if ($range !== '' && preg_match('/bytes=(\d+)-(\d*)/', $range, $m)) {
    $start = (int)$m[1];
    $end   = $m[2] !== '' ? (int)$m[2] : $size - 1;
    if ($start > $end || $end >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    http_response_code(206);
    header('Content-Type: ' . $mime);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    header('Content-Length: ' . ($end - $start + 1));
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    $fp = fopen($abs, 'rb');
    fseek($fp, $start);
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = min(8192, $remaining);
        echo fread($fp, $chunk);
        $remaining -= $chunk;
        flush();
    }
    fclose($fp);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($abs);