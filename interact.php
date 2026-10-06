<?php
session_start();
require 'config/db.php';
require 'includes/csrf.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: feed.php');
    exit;
}
csrf_verify();

$user_id = (int)$_SESSION['user_id'];
$action  = $_POST['action'] ?? '';
$post_id = (int)($_POST['post_id'] ?? 0);
$redirect = $_POST['redirect'] ?? 'feed.php';

// Safety: only allow redirect to a known safe page
$allowed_redirects = ['feed.php', 'profile.php', 'search.php'];
$redirect_base = strtok($redirect, '?');
if (!in_array($redirect_base, $allowed_redirects, true)) {
    $redirect = 'feed.php';
}

if ($post_id <= 0) {
    header("Location: $redirect");
    exit;
}

// Verify the post exists and get its owner
$stmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = :id");
$stmt->execute([':id' => $post_id]);
$post_owner = $stmt->fetchColumn();

if ($post_owner === false) {
    header("Location: $redirect");
    exit;
}

$post_owner = (int)$post_owner;

if ($action === 'like') {
    // Toggle like
    $stmt = $pdo->prepare("SELECT id FROM likes WHERE user_id = :u AND post_id = :p");
    $stmt->execute([':u' => $user_id, ':p' => $post_id]);

    if ($stmt->fetch()) {
        // Unlike — remove the like AND any notification for it
        $stmt = $pdo->prepare("DELETE FROM likes WHERE user_id = :u AND post_id = :p");
        $stmt->execute([':u' => $user_id, ':p' => $post_id]);

        // Remove the matching notification so the owner doesn't see stale ones
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = :owner AND actor_id = :actor AND type = 'like' AND post_id = :p");
        $stmt->execute([':owner' => $post_owner, ':actor' => $user_id, ':p' => $post_id]);
    } else {
        // Like — insert + notify (unless liking own post)
        $stmt = $pdo->prepare("INSERT INTO likes (user_id, post_id) VALUES (:u, :p)");
        $stmt->execute([':u' => $user_id, ':p' => $post_id]);

        if ($post_owner !== $user_id) {
            // Avoid duplicates: only create notification if none exists
            $stmt = $pdo->prepare("
                SELECT id FROM notifications
                WHERE user_id = :owner AND actor_id = :actor AND type = 'like' AND post_id = :p
            ");
            $stmt->execute([':owner' => $post_owner, ':actor' => $user_id, ':p' => $post_id]);

            if (!$stmt->fetch()) {
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, actor_id, type, post_id)
                    VALUES (:owner, :actor, 'like', :p)
                ");
                $stmt->execute([':owner' => $post_owner, ':actor' => $user_id, ':p' => $post_id]);
            }
        }
    }
} elseif ($action === 'comment') {
    $content = trim($_POST['content'] ?? '');
    if ($content !== '' && mb_strlen($content) <= 500) {
        $stmt = $pdo->prepare("INSERT INTO comments (user_id, post_id, content) VALUES (:u, :p, :c)");
        $stmt->execute([':u' => $user_id, ':p' => $post_id, ':c' => $content]);

        // Notify post owner (unless commenting on own post)
        if ($post_owner !== $user_id) {
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, actor_id, type, post_id)
                VALUES (:owner, :actor, 'comment', :p)
            ");
            $stmt->execute([':owner' => $post_owner, ':actor' => $user_id, ':p' => $post_id]);
        }
    }
}

header("Location: $redirect");
exit;