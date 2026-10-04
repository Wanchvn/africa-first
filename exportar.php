<?php
session_start();
require 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Gather all the user's data
$data = [];

// 1. Profile
$stmt = $pdo->prepare("
    SELECT id, username, email, avatar, created_at
    FROM users
    WHERE id = :id
");
$stmt->execute([':id' => $user_id]);
$data['profile'] = $stmt->fetch();

// 2. Posts
$stmt = $pdo->prepare("
    SELECT id, content, media_path, created_at
    FROM posts
    WHERE user_id = :id
    ORDER BY created_at ASC
");
$stmt->execute([':id' => $user_id]);
$data['posts'] = $stmt->fetchAll();

// 3. Comments
$stmt = $pdo->prepare("
    SELECT comments.id, comments.post_id, comments.content, comments.created_at,
           posts.content AS post_content, posts.user_id AS post_author
    FROM comments
    INNER JOIN posts ON comments.post_id = posts.id
    WHERE comments.user_id = :id
    ORDER BY comments.created_at ASC
");
$stmt->execute([':id' => $user_id]);
$data['comments'] = $stmt->fetchAll();

// 4. Likes
$stmt = $pdo->prepare("
    SELECT likes.id, likes.post_id, likes.created_at,
           posts.content AS post_content, posts.user_id AS post_author
    FROM likes
    INNER JOIN posts ON likes.post_id = posts.id
    WHERE likes.user_id = :id
    ORDER BY likes.created_at ASC
");
$stmt->execute([':id' => $user_id]);
$data['likes'] = $stmt->fetchAll();

// 5. Follows
$stmt = $pdo->prepare("
    SELECT u.id, u.username, f.created_at
    FROM follows f
    INNER JOIN users u ON f.following_id = u.id
    WHERE f.follower_id = :id
    ORDER BY f.created_at ASC
");
$stmt->execute([':id' => $user_id]);
$data['following'] = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT u.id, u.username, f.created_at
    FROM follows f
    INNER JOIN users u ON f.follower_id = u.id
    WHERE f.following_id = :id
    ORDER BY f.created_at ASC
");
$stmt->execute([':id' => $user_id]);
$data['followers'] = $stmt->fetchAll();

// 6. Notifications
$stmt = $pdo->prepare("
    SELECT n.id, n.type, n.post_id, n.is_read, n.created_at,
           u.username AS actor_username
    FROM notifications n
    INNER JOIN users u ON n.actor_id = u.id
    WHERE n.user_id = :id
    ORDER BY n.created_at ASC
");
$stmt->execute([':id' => $user_id]);
$data['notifications'] = $stmt->fetchAll();

// Build the ZIP
$zip = new ZipArchive();
$username = preg_replace('/[^a-zA-Z0-9_-]/', '', $data['profile']['username']);
$filename = 'qarota_export_' . $username . '_' . date('Y-m-d') . '.zip';

// Use a temp file — the download will be served right after
$tempFile = tempnam(sys_get_temp_dir(), 'qarota_export_');

if ($zip->open($tempFile, ZipArchive::OVERWRITE) !== true) {
    die('Could not create ZIP archive.');
}

// Add JSON files — one per data category
$zip->addFromString('profile.json', json_encode($data['profile'], JSON_PRETTY_PRINT));
$zip->addFromString('posts.json', json_encode($data['posts'], JSON_PRETTY_PRINT));
$zip->addFromString('comments.json', json_encode($data['comments'], JSON_PRETTY_PRINT));
$zip->addFromString('likes.json', json_encode($data['likes'], JSON_PRETTY_PRINT));
$zip->addFromString('following.json', json_encode($data['following'], JSON_PRETTY_PRINT));
$zip->addFromString('followers.json', json_encode($data['followers'], JSON_PRETTY_PRINT));
$zip->addFromString('notifications.json', json_encode($data['notifications'], JSON_PRETTY_PRINT));

// Add media files (post images + avatar)
$mediaFolder = __DIR__ . '/uploads';
$addedCount = 0;

foreach ($data['posts'] as $post) {
    if (!empty($post['media_path'])) {
        $fullPath = __DIR__ . '/' . $post['media_path'];
        if (file_exists($fullPath)) {
            $zip->addFile($fullPath, $post['media_path']);
            $addedCount++;
        }
    }
}

if (!empty($data['profile']['avatar'])) {
    $avatarPath = __DIR__ . '/' . $data['profile']['avatar'];
    if (file_exists($avatarPath)) {
        $zip->addFile($avatarPath, $data['profile']['avatar']);
        $addedCount++;
    }
}

// Add a README so the user knows what's inside
$readme = "Qarota Data Export\n"
        . "==================\n\n"
        . "This archive contains everything Qarota knows about your account.\n"
        . "Exported on: " . date('Y-m-d H:i:s') . "\n"
        . "Account: " . $data['profile']['username'] . "\n\n"
        . "Files included:\n"
        . "- profile.json — your account info\n"
        . "- posts.json — all your posts\n"
        . "- comments.json — all your comments\n"
        . "- likes.json — every post you liked\n"
        . "- following.json — users you follow\n"
        . "- followers.json — users who follow you\n"
        . "- notifications.json — your notification history\n"
        . "- uploads/ — your photos (post images and avatar)\n\n"
        . "This data is yours. Keep it safe.\n";

$zip->addFromString('README.txt', $readme);

$zip->close();

// Send the file to the browser as a download
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tempFile));
header('Cache-Control: no-cache, no-store, must-revalidate');

readfile($tempFile);
unlink($tempFile); // Delete the temp file after sending
exit;