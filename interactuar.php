<?php
session_start();
require 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: feed.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$action  = $_POST['action'] ?? '';
$post_id = (int)($_POST['post_id'] ?? 0);
$redirect = $_POST['redirect'] ?? 'feed.php';

// Safety: only allow redirect to a known safe page
$allowed_redirects = ['feed.php', 'perfil.php'];
$redirect_base = strtok($redirect, '?');
if (!in_array($redirect_base, $allowed_redirects, true)) {
    $redirect = 'feed.php';
}

if ($post_id <= 0) {
    header("Location: $redirect");
    exit;
}

// Verify the post exists
$stmt = $pdo->prepare("SELECT id FROM posts WHERE id = :id");
$stmt->execute([':id' => $post_id]);
if (!$stmt->fetch()) {
    header("Location: $redirect");
    exit;
}

if ($action === 'like') {
    // Toggle like
    $stmt = $pdo->prepare("SELECT id FROM likes WHERE user_id = :u AND post_id = :p");
    $stmt->execute([':u' => $user_id, ':p' => $post_id]);

    if ($stmt->fetch()) {
        $stmt = $pdo->prepare("DELETE FROM likes WHERE user_id = :u AND post_id = :p");
        $stmt->execute([':u' => $user_id, ':p' => $post_id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO likes (user_id, post_id) VALUES (:u, :p)");
        $stmt->execute([':u' => $user_id, ':p' => $post_id]);
    }
} elseif ($action === 'comment') {
    $content = trim($_POST['content'] ?? '');
    if ($content !== '' && strlen($content) <= 500) {
        $stmt = $pdo->prepare("INSERT INTO comments (user_id, post_id, content) VALUES (:u, :p, :c)");
        $stmt->execute([':u' => $user_id, ':p' => $post_id, ':c' => $content]);
    }
}

header("Location: $redirect");
exit;