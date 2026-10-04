<?php
session_start();
require 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$my_id = $_SESSION['user_id'];
$target_id = (int)$_POST['target_id'];

// Can't follow yourself
if ($target_id === $my_id) {
    header('Location: perfil.php');
    exit;
}

// Check if already following
$stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = :me AND following_id = :them");
$stmt->execute([':me' => $my_id, ':them' => $target_id]);

if ($stmt->fetch()) {
    // Already following — unfollow
    $stmt = $pdo->prepare("DELETE FROM follows WHERE follower_id = :me AND following_id = :them");
    $stmt->execute([':me' => $my_id, ':them' => $target_id]);
} else {
    // Not following — follow
    $stmt = $pdo->prepare("INSERT INTO follows (follower_id, following_id) VALUES (:me, :them)");
    $stmt->execute([':me' => $my_id, ':them' => $target_id]);
}

header('Location: perfil.php?id=' . $target_id);
exit;