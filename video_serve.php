<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();
require 'config/db.php';
require 'includes/crypto.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit;
}

$me = (int)$_SESSION['user_id'];
$stmt = $pdo->prepare(
    "SELECT m.video_path, c.user1_id, c.user2_id
     FROM messages m
     INNER JOIN conversations c ON c.id = m.conversation_id
     WHERE m.id = :id AND m.message_type = 'video'
     LIMIT 1"
);
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    exit;
}
if ((int)$row['user1_id'] !== $me && (int)$row['user2_id'] !== $me) {
    http_response_code(403);
    exit;
}

$relativePath = (string)$row['video_path'];
$videoDir = realpath(__DIR__ . '/uploads/dm_videos');
$filePath = realpath(__DIR__ . '/' . $relativePath);
if (
    $videoDir === false
    || strpos($relativePath, 'uploads/dm_videos/') !== 0
    || $filePath === false
    || strpos($filePath, $videoDir . DIRECTORY_SEPARATOR) !== 0
    || !is_file($filePath)
    || !is_readable($filePath)
) {
    http_response_code(404);
    exit;
}

$blob = file_get_contents($filePath);
if ($blob === false) {
    http_response_code(500);
    exit;
}

try {
    $video = crypto_decrypt_blob($blob);
} catch (Throwable $e) {
    error_log('Video serving decryption failed: ' . $e->getMessage());
    http_response_code(500);
    exit;
}
if ($video === '') {
    http_response_code(500);
    exit;
}

$originalPath = preg_replace('/\.enc$/i', '', $relativePath);
$ext = strtolower(pathinfo($originalPath, PATHINFO_EXTENSION));
$mime = [
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
    'mov'  => 'video/quicktime',
    'mkv'  => 'video/x-matroska',
][$ext] ?? 'application/octet-stream';

$size = strlen($video);
$start = 0;
$end = $size - 1;
$status = 200;
$range = $_SERVER['HTTP_RANGE'] ?? '';
if ($range !== '') {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/D', trim($range), $matches)
        || ($matches[1] === '' && $matches[2] === '')
    ) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }

    if ($matches[1] === '') {
        $suffixLength = (int)$matches[2];
        if ($suffixLength <= 0) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $start = max(0, $size - $suffixLength);
    } else {
        $start = (int)$matches[1];
        if ($matches[2] !== '') {
            $end = (int)$matches[2];
        }
    }

    if ($start >= $size || $end < $start) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }

    $end = min($end, $size - 1);
    $status = 206;
}

$length = $end - $start + 1;
http_response_code($status);
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');
header('Content-Length: ' . $length);
header('Cache-Control: private, max-age=3600');
if ($status === 206) {
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}
if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    echo substr($video, $start, $length);
}
