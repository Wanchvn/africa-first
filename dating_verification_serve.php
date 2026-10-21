<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require_once __DIR__ . '/includes/dating.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}

$me    = (int)$_SESSION['user_id'];
$verId = (int)($_GET['id'] ?? 0);
if ($verId <= 0) {
    http_response_code(404);
    exit;
}

// Is the viewer an admin?
$stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $me]);
$isAdmin = (int)$stmt->fetchColumn() === 1;

$ver = get_dating_verification_by_id($pdo, $verId);
if (!$ver) {
    http_response_code(404);
    exit;
}

$isOwner = (int)$ver['user_id'] === $me;

if (!$isOwner && !$isAdmin) {
    http_response_code(403);
    exit;
}

$abs = __DIR__ . '/' . $ver['selfie_path'];
if (!is_file($abs)) {
    http_response_code(404);
    exit;
}

$mime = function_exists('mime_content_type') ? mime_content_type($abs) : 'image/jpeg';
if (!$mime) $mime = 'image/jpeg';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Cache-Control: private, max-age=300');
readfile($abs);
exit;