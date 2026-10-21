<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/dating.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error(405, 'Method not allowed.');

$user = api_require_admin($pdo);
$me = (int)$user['id'];

if (empty($_FILES['photo']['name']) || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    api_error(400, 'No photo uploaded.');
}

$file = $_FILES['photo'];
$max = 8 * 1024 * 1024; // 8MB
if ($file['size'] > $max) api_error(400, 'Photo must be smaller than 8 MB.');

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($allowed[$mime])) api_error(400, 'Only JPG, PNG, or WebP.');

$ext = $allowed[$mime];
$subdir = 'uploads/dating_photos/' . $me;
$absDir = __DIR__ . '/../' . $subdir;
if (!is_dir($absDir) && !mkdir($absDir, 0755, true) && !is_dir($absDir)) api_error(500, 'Could not create directory.');

$name = bin2hex(random_bytes(16)) . '.' . $ext;
$abs = $absDir . '/' . $name;
$rel = $subdir . '/' . $name;

if (!move_uploaded_file($file['tmp_name'], $abs)) api_error(500, 'Could not save file.');

try {
    add_dating_photo($pdo, $me, $rel);
    api_ok(['message' => 'Photo added.']);
} catch (Throwable $e) {
    @unlink($abs);
    api_error(400, $e->getMessage());
}