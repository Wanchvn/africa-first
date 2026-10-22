<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/dating.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') api_error(405, 'Method not allowed.');

$user = api_require_admin($pdo);
$verificationId = (int)($_GET['id'] ?? 0);
if ($verificationId <= 0) api_error(400, 'Invalid verification ID.');

$verification = get_dating_verification_by_id($pdo, $verificationId);
if (!$verification) api_error(404, 'Verification not found.');

$storageRoot = realpath(__DIR__ . '/../uploads/dating_verifications');
$imagePath = realpath(__DIR__ . '/../' . $verification['selfie_path']);
if (
    !$storageRoot ||
    !$imagePath ||
    strpos($imagePath, $storageRoot . DIRECTORY_SEPARATOR) !== 0 ||
    !is_file($imagePath)
) {
    api_error(404, 'Verification image not found.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($imagePath);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    api_error(404, 'Verification image not found.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($imagePath));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($imagePath);
exit;
