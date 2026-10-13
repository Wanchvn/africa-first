<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require 'includes/messages.php';
require 'includes/conversations.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: messages.php');
    exit;
}

csrf_verify();
rate_limit_enforce($pdo, client_ip(), 'group_avatar', 10, 3600);

$me = (int)$_SESSION['user_id'];
$convId = (int)($_POST['conversation_id'] ?? 0);
$upload = $_FILES['group_avatar'] ?? null;

$redirect = static function (string $message) use ($convId): void {
    $_SESSION['flash'] = $message;
    header('Location: conversation.php?id=' . $convId);
    exit;
};

if ($convId <= 0 || !user_in_conversation($pdo, $convId, $me) || !is_conversation_admin($pdo, $convId, $me)) {
    $redirect(__('group_last_admin_error'));
}

if (!is_array($upload) || empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
    $redirect('Please choose an image to upload.');
}

$allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$fileType = strtolower((string)($upload['type'] ?? ''));
$fileSize = (int)($upload['size'] ?? 0);
if (!isset($allowedTypes[$fileType]) || $fileSize <= 0 || $fileSize > 2 * 1024 * 1024) {
    $redirect('Only JPG, PNG, or WebP images under 2 MB are allowed.');
}

$targetDir = __DIR__ . '/uploads/group_avatars';
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0777, true);
}

$extension = $allowedTypes[$fileType];
$filename = bin2hex(random_bytes(8)) . '.' . $extension;
$targetPath = $targetDir . '/' . $filename;
if (!move_uploaded_file($upload['tmp_name'], $targetPath)) {
    $redirect('Could not save the group avatar.');
}

$stmt = $pdo->prepare(
    'UPDATE conversations SET group_avatar = :avatar WHERE id = :id AND is_group = 1'
);
$stmt->execute([':avatar' => 'uploads/group_avatars/' . $filename, ':id' => $convId]);

$_SESSION['flash'] = __('group_avatar_updated');
header('Location: conversation.php?id=' . $convId);
exit;
