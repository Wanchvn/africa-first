<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$admin_id = (int)$_SESSION['user_id'];

// Admin gate
$stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $admin_id]);
if ((int)$stmt->fetchColumn() !== 1) {
    http_response_code(403);
    exit;
}

$app_id = (int)($_GET['id'] ?? 0);
$slot   = $_GET['slot'] ?? '';
if ($app_id <= 0 || !in_array($slot, ['front','back','selfie'], true)) {
    http_response_code(400);
    exit;
}

$col = match ($slot) {
    'front'  => 'id_front_path',
    'back'   => 'id_back_path',
    'selfie' => 'selfie_path',
};

$stmt = $pdo->prepare("SELECT $col AS p FROM verification_applications WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $app_id]);
$rel = $stmt->fetchColumn();

if (!$rel) {
    http_response_code(404);
    exit;
}

// Whitelist the path prefix, resolve, and confirm it's inside storage/verifications
if (!preg_match('#^storage/verifications/[0-9]+/[A-Za-z0-9._-]+$#', $rel)) {
    http_response_code(404);
    exit;
}

$projectRoot  = __DIR__;
$storageRoot  = realpath($projectRoot . '/storage/verifications');
$filePath     = realpath($projectRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel));

if (
    $storageRoot === false
    || $filePath === false
    || strpos($filePath, $storageRoot . DIRECTORY_SEPARATOR) !== 0
    || !is_file($filePath)
    || !is_readable($filePath)
) {
    http_response_code(404);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($filePath);
if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
    http_response_code(415);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: inline');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
exit;