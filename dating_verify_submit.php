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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dating_verify.php');
    exit;
}

csrf_verify();
rate_limit_enforce($pdo, client_ip(), 'dating_verify_submit', 5, 3600);

$me = (int)$_SESSION['user_id'];

if (!is_dating_active($pdo, $me)) {
    $_SESSION['flash_error'] = 'Activate your dating profile first.';
    header('Location: dating_setup.php');
    exit;
}

$code = trim((string)($_POST['code'] ?? ''));

// Prefer the code we generated in the session; fall back to POST if needed.
$expected = $_SESSION['dating_verify_code'] ?? '';
if ($expected === '' || $code === '' || !hash_equals($expected, $code)) {
    $_SESSION['flash_error'] = 'Verification code mismatch. Try again.';
    header('Location: dating_verify.php');
    exit;
}

if (empty($_FILES['selfie']['name'] ?? '') || ($_FILES['selfie']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $_SESSION['flash_error'] = 'Please attach a selfie.';
    header('Location: dating_verify.php');
    exit;
}

$file = $_FILES['selfie'];
if ($file['size'] > 8 * 1024 * 1024) {
    $_SESSION['flash_error'] = 'Selfie must be smaller than 8 MB.';
    header('Location: dating_verify.php');
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);
$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];
if (!isset($allowed[$mime])) {
    $_SESSION['flash_error'] = 'Only JPG, PNG, or WebP.';
    header('Location: dating_verify.php');
    exit;
}
$ext = $allowed[$mime];

$subdir = 'uploads/dating_verifications/' . $me;
$absDir = __DIR__ . '/' . $subdir;
if (!is_dir($absDir) && !mkdir($absDir, 0755, true) && !is_dir($absDir)) {
    $_SESSION['flash_error'] = 'Could not create upload directory.';
    header('Location: dating_verify.php');
    exit;
}

$name = bin2hex(random_bytes(16)) . '.' . $ext;
$abs  = $absDir . '/' . $name;
$rel  = $subdir . '/' . $name;

if (!move_uploaded_file($file['tmp_name'], $abs)) {
    $_SESSION['flash_error'] = 'Could not save the selfie.';
    header('Location: dating_verify.php');
    exit;
}
@chmod($abs, 0640);

try {
    submit_dating_verification($pdo, $me, $code, $rel);
    // Clear the code so the next attempt gets a fresh one.
    unset($_SESSION['dating_verify_code']);
    $_SESSION['flash_success'] = 'Submitted. An admin will review within 24 hours.';
} catch (Throwable $e) {
    @unlink($abs);
    error_log('dating_verify_submit error: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Could not submit. Try again.';
}

header('Location: dating_verify.php');
exit;