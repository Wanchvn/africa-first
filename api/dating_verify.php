<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/dating.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error(405, 'Method not allowed.');

$user = api_require_auth($pdo);
$me = (int)$user['id'];

$code = trim($_POST['code'] ?? '');
if (!preg_match('/^\d{4}$/D', $code)) {
    api_error(400, 'Enter a valid 4-digit verification code.');
}

if (($_FILES['selfie']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    if (in_array($_FILES['selfie']['error'] ?? UPLOAD_ERR_NO_FILE, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        api_error(413, 'Selfie must be smaller than 8 MB.');
    }
    api_error(400, 'No selfie uploaded.');
}

$file = $_FILES['selfie'];
$max = 8 * 1024 * 1024;
if ((int)$file['size'] <= 0) api_error(400, 'No selfie uploaded.');
if ((int)$file['size'] > $max) api_error(413, 'Selfie must be smaller than 8 MB.');
if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    api_error(400, 'Invalid selfie upload.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
    api_error(400, 'Only valid JPG, PNG, or WebP images are accepted.');
}
$ext = $allowed[$mime];

$subdir = 'uploads/dating_verifications/' . $me;
$absDir = __DIR__ . '/../' . $subdir;
if (!is_dir($absDir) && !mkdir($absDir, 0755, true) && !is_dir($absDir)) {
    api_error(500, 'Could not create directory.');
}

$name = bin2hex(random_bytes(16)) . '.' . $ext;
$abs = $absDir . '/' . $name;
$rel = $subdir . '/' . $name;

if (!move_uploaded_file($file['tmp_name'], $abs)) {
    api_error(500, 'Could not save file.');
}

try {
    submit_dating_verification($pdo, $me, $code, $rel);
    api_ok(['message' => 'Verification submitted.']);
} catch (Throwable $e) {
    @unlink($abs);
    error_log('Dating verification submission failed: ' . get_class($e));
    api_error(500, 'Could not submit verification.');
}