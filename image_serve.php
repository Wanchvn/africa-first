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
    "SELECT m.image_path, c.user1_id, c.user2_id
     FROM messages m
     INNER JOIN conversations c ON c.id = m.conversation_id
     WHERE m.id = :id AND m.message_type = 'image'
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

$relativePath = (string)$row['image_path'];
$imageDir = realpath(__DIR__ . '/uploads/dm_images');
$filePath = realpath(__DIR__ . '/' . $relativePath);
if (
    $imageDir === false
    || strpos($relativePath, 'uploads/dm_images/') !== 0
    || $filePath === false
    || strpos($filePath, $imageDir . DIRECTORY_SEPARATOR) !== 0
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

$image = crypto_decrypt_blob($blob);
$originalPath = preg_replace('/\.enc$/i', '', $relativePath);
$ext = strtolower(pathinfo($originalPath, PATHINFO_EXTENSION));
$mime = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
][$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen($image));
header('Cache-Control: private, max-age=3600');
echo $image;
