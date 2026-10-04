<?php
session_start();
require 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$my_id = (int)$_SESSION['user_id'];
$target_id = (int)($_POST['target_id'] ?? 0);

if ($target_id <= 0 || $target_id === $my_id) {
    header('Location: descubrir.php');
    exit;
}

// Verify target user exists
$stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id");
$stmt->execute([':id' => $target_id]);
if (!$stmt->fetch()) {
    header('Location: descubrir.php');
    exit;
}

// Check if already following
$stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = :me AND following_id = :them");
$stmt->execute([':me' => $my_id, ':them' => $target_id]);

if ($stmt->fetch()) {
    // Unfollow — remove follow AND any follow notification
    $stmt = $pdo->prepare("DELETE FROM follows WHERE follower_id = :me AND following_id = :them");
    $stmt->execute([':me' => $my_id, ':them' => $target_id]);

    $stmt = $pdo->prepare("
        DELETE FROM notifications
        WHERE user_id = :them AND actor_id = :me AND type = 'follow'
    ");
    $stmt->execute([':them' => $target_id, ':me' => $my_id]);
} else {
    // Follow — insert AND notify
    $stmt = $pdo->prepare("INSERT INTO follows (follower_id, following_id) VALUES (:me, :them)");
    $stmt->execute([':me' => $my_id, ':them' => $target_id]);

    // Avoid duplicates (in case of weird states)
    $stmt = $pdo->prepare("
        SELECT id FROM notifications
        WHERE user_id = :them AND actor_id = :me AND type = 'follow'
    ");
    $stmt->execute([':them' => $target_id, ':me' => $my_id]);

    if (!$stmt->fetch()) {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, actor_id, type)
            VALUES (:them, :me, 'follow')
        ");
        $stmt->execute([':them' => $target_id, ':me' => $my_id]);
    }
}

// Redirect back to where the action came from
$redirect = $_POST['redirect'] ?? 'descubrir.php';
$allowed = ['descubrir.php', 'perfil.php'];
$redirect_base = strtok($redirect, '?');
if (!in_array($redirect_base, $allowed, true)) {
    $redirect = 'descubrir.php';
}

header("Location: $redirect");
exit;