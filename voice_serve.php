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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit;
}

$me = (int)$_SESSION['user_id'];
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
$voiceDir = realpath(__DIR__ . '/uploads/voice');
$filePath = realpath(__DIR__ . '/' . $relativePath);
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
