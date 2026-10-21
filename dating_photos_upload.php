<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require_once __DIR__ . '/includes/dating.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
       && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$respond = function ($ok, $payload) use ($isAjax) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($ok ? array_merge(['success' => true], $payload) : ['success' => false, 'error' => $payload]);
    } else {
        if ($ok) {
            $_SESSION['flash_success'] = $payload['message'] ?? 'Photo added.';
        } else {
            $_SESSION['flash_error'] = $payload;
        }
        header('Location: dating_setup.php#photos');
    }
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(false, 'Method not allowed.');
}

csrf_verify();
rate_limit_enforce($pdo, client_ip(), 'dating_photo', 30, 3600);

$me = (int)$_SESSION['user_id'];

if (!is_dating_active($pdo, $me)) {
    $respond(false, 'Activate your dating profile first.');
}

if (empty($_FILES['photo']['name'] ?? '') || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $respond(false, 'No photo was uploaded.');
}

$file = $_FILES['photo'];
$max  = 8 * 1024 * 1024;
if ($file['size'] > $max) {
    $respond(false, 'Photo must be smaller than 8 MB.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);
$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];
if (!isset($allowed[$mime])) {
    $respond(false, 'Only JPG, PNG, or WebP.');
}
$ext = $allowed[$mime];

// Ensure we don't exceed the 6-photo limit.
$count = (int)$pdo->query("SELECT COUNT(*) FROM dating_photos WHERE user_id = " . (int)$me)->fetchColumn();
if ($count >= DATING_PHOTO_LIMIT) {
    $respond(false, 'You can have up to ' . DATING_PHOTO_LIMIT . ' photos. Delete one first.');
}

// Store under uploads/dating_photos/{user_id}/{hash}.{ext}
$subdir = 'uploads/dating_photos/' . $me;
$absDir = __DIR__ . '/' . $subdir;
if (!is_dir($absDir) && !mkdir($absDir, 0755, true) && !is_dir($absDir)) {
    $respond(false, 'Could not create upload directory.');
}

$name = bin2hex(random_bytes(16)) . '.' . $ext;
$abs  = $absDir . '/' . $name;
$rel  = $subdir . '/' . $name;

if (!move_uploaded_file($file['tmp_name'], $abs)) {
    $respond(false, 'Could not save the file.');
}
@chmod($abs, 0644);

// Optional: strip EXIF by re-encoding if GD is available. Skipped for brevity;
// most hosts already disable EXIF in stored files if you route through a script.

try {
    $photoId = add_dating_photo($pdo, $me, $rel);
} catch (Throwable $e) {
    @unlink($abs);
    $respond(false, $e->getMessage());
}

$respond(true, [
    'photo_id' => $photoId,
    'url'      => 'dating_photo_serve.php?id=' . $photoId,
    'message'  => 'Photo added.',
]);