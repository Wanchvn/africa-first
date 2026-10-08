<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'includes/csrf.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: saved.php');
    exit;
}

csrf_verify();

$user_id = (int)$_SESSION['user_id'];
$post_id = (int)($_POST['post_id'] ?? 0);
$folder_id = (int)($_POST['folder_id'] ?? 0);
$redirect = $_POST['redirect'] ?? 'saved.php';

// Whitelist redirect
$allowed = ['saved.php'];
$redirect_base = strtok($redirect, '?');
if (!in_array($redirect_base, $allowed, true)) {
    $redirect = 'saved.php';
}

if ($post_id <= 0) {
    header('Location: ' . $redirect);
    exit;
}

// folder_id = 0 means "move to Saved (default / NULL)"
$folder_value = $folder_id > 0 ? $folder_id : null;

// If a specific folder is set, verify ownership
if ($folder_value !== null) {
    $stmt = $pdo->prepare("SELECT id FROM bookmark_folders WHERE id = :id AND user_id = :u");
    $stmt->execute([':id' => $folder_value, ':u' => $user_id]);
    if (!$stmt->fetch()) {
        header('Location: ' . $redirect);
        exit;
    }
}

// Update the bookmark — only if it belongs to this user
$stmt = $pdo->prepare("
    UPDATE bookmarks
    SET folder_id = :f
    WHERE user_id = :u AND post_id = :p
");
$stmt->execute([
    ':f' => $folder_value,
    ':u' => $user_id,
    ':p' => $post_id,
]);

header('Location: ' . $redirect);
exit;