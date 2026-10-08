<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: saved.php');
    exit;
}

csrf_verify();
rate_limit_enforce($pdo, client_ip(), 'bookmark_folder', 20, 300);

$user_id = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';
$redirect = $_POST['redirect'] ?? 'saved.php';

// Whitelist redirect
$allowed = ['saved.php'];
$redirect_base = strtok($redirect, '?');
if (!in_array($redirect_base, $allowed, true)) {
    $redirect = 'saved.php';
}

if ($action === 'create') {
    $name = trim($_POST['name'] ?? '');

    if ($name === '') {
        $_SESSION['folder_message'] = 'Folder name cannot be empty.';
    } elseif (mb_strlen($name) > 50) {
        $_SESSION['folder_message'] = 'Folder name must be 50 characters or less.';
    } else {
        // Check if a folder with this name already exists
        $stmt = $pdo->prepare("SELECT id FROM bookmark_folders WHERE user_id = :u AND name = :n");
        $stmt->execute([':u' => $user_id, ':n' => $name]);

        if ($stmt->fetch()) {
            $_SESSION['folder_message'] = 'You already have a folder with that name.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO bookmark_folders (user_id, name) VALUES (:u, :n)");
            $stmt->execute([':u' => $user_id, ':n' => $name]);
            $_SESSION['folder_message'] = 'Folder created.';
        }
    }
} elseif ($action === 'delete') {
    $folder_id = (int)($_POST['folder_id'] ?? 0);

    if ($folder_id > 0) {
        // Verify ownership
        $stmt = $pdo->prepare("SELECT id FROM bookmark_folders WHERE id = :id AND user_id = :u");
        $stmt->execute([':id' => $folder_id, ':u' => $user_id]);

        if ($stmt->fetch()) {
            // Move its bookmarks to the default folder (NULL)
            $stmt = $pdo->prepare("UPDATE bookmarks SET folder_id = NULL WHERE folder_id = :f AND user_id = :u");
            $stmt->execute([':f' => $folder_id, ':u' => $user_id]);

            // Delete the folder
            $stmt = $pdo->prepare("DELETE FROM bookmark_folders WHERE id = :id AND user_id = :u");
            $stmt->execute([':id' => $folder_id, ':u' => $user_id]);

            $_SESSION['folder_message'] = 'Folder deleted. Its bookmarks were moved to "Saved".';
        }
    }
}

header('Location: ' . $redirect);
exit;