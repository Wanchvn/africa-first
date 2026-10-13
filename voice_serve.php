<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();
require 'config/db.php';
require 'includes/crypto.php';
require 'includes/posts.php';
require 'includes/blocks.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$me   = (int)$_SESSION['user_id'];
$id   = isset($_GET['id'])   ? (int)$_GET['id'] : 0;
$type = $_GET['type'] ?? 'message';   // 'message' (default, backwards-compat) or 'post'

if ($id <= 0) {
    http_response_code(400);
    exit;
}

// =====================================================================
// POST voice note — plain (unencrypted) file, permission = can_view_post()
// =====================================================================
if ($type === 'post') {
    $stmt = $pdo->prepare(
        "SELECT id, user_id, media_type, media_path, media_mime,
                media_duration, visibility
         FROM posts
         WHERE id = :id AND media_type = 'voice'
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post || empty($post['media_path'])) {
        http_response_code(404);
        exit;
    }

    if (!can_view_post($pdo, $post, $me)) {
        http_response_code(403);
        exit;
    }

    $relativePath = (string)$post['media_path'];

    // Lock the resolved file to uploads/ (voice/ or private_posts/)
    $uploadsRoot = realpath(__DIR__ . '/uploads');
    $filePath    = realpath(__DIR__ . '/' . $relativePath);

    if (
        $uploadsRoot === false
        || $filePath === false
        || !preg_match('#^uploads/(voice|private_posts)/#', $relativePath)
        || strpos($filePath, $uploadsRoot . DIRECTORY_SEPARATOR) !== 0
        || !is_file($filePath)
        || !is_readable($filePath)
    ) {
        http_response_code(404);
        exit;
    }

    $mime = $post['media_mime'] ?: 'audio/webm';
    $size = filesize($filePath);
    $cacheControl = ($post['visibility'] ?? 'public') === 'public'
        ? 'public, max-age=86400'
        : 'private, max-age=3600';

    // --- Range request support (needed for seeking) ---
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
        header('Cache-Control: ' . $cacheControl);
        header('X-Content-Type-Options: nosniff');

        $fp = fopen($filePath, 'rb');
        if ($fp === false) {
            http_response_code(500);
            exit;
        }
        fseek($fp, $start);
        $remaining  = $end - $start + 1;
        $chunk_size = 8192;
        while ($remaining > 0 && !feof($fp)) {
            $read = min($chunk_size, $remaining);
            echo fread($fp, $read);
            $remaining -= $read;
            flush();
        }
        fclose($fp);
        exit;
    }

    // --- Full file ---
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('Accept-Ranges: bytes');
    header('Cache-Control: ' . $cacheControl);
    header('X-Content-Type-Options: nosniff');
    readfile($filePath);
    exit;
}

// =====================================================================
// MESSAGE voice note — existing encrypted flow, unchanged
// =====================================================================
$stmt = $pdo->prepare(
    "SELECT m.voice_path, c.user1_id, c.user2_id
     FROM messages m
     INNER JOIN conversations c ON c.id = m.conversation_id
     WHERE m.id = :id AND m.message_type = 'voice'
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

$relativePath = (string)$row['voice_path'];
$voiceDir     = realpath(__DIR__ . '/uploads/voice');
$filePath     = realpath(__DIR__ . '/' . $relativePath);
if (
    $voiceDir === false
    || strpos($relativePath, 'uploads/voice/') !== 0
    || $filePath === false
    || strpos($filePath, $voiceDir . DIRECTORY_SEPARATOR) !== 0
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

$audio = crypto_decrypt_blob($blob);
$originalPath = preg_replace('/\.enc$/i', '', $relativePath);
$ext = strtolower(pathinfo($originalPath, PATHINFO_EXTENSION));
$mime = [
    'webm' => 'audio/webm',
    'ogg'  => 'audio/ogg',
    'm4a'  => 'audio/mp4',
    'mp3'  => 'audio/mpeg',
    'wav'  => 'audio/wav',
][$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen($audio));
header('Accept-Ranges: none');
header('Cache-Control: private, max-age=3600');
echo $audio;