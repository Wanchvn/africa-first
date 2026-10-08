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

csrf_verify();

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$my_id = (int)$_SESSION['user_id'];
$target_id = (int)($_POST['target_id'] ?? 0);

if ($target_id <= 0 || $target_id === $my_id) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid target']);
        exit;
    }
    header('Location: discover.php');
    exit;
}

// Verify target user exists
$stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id");
$stmt->execute([':id' => $target_id]);
if (!$stmt->fetch()) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    header('Location: discover.php');
    exit;
}

rate_limit_enforce($pdo, client_ip(), 'follow', 30, 300);

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

    $new_state = 'unfollowed';
} else {
    // Follow — insert AND notify
    $stmt = $pdo->prepare("INSERT INTO follows (follower_id, following_id) VALUES (:me, :them)");
    $stmt->execute([':me' => $my_id, ':them' => $target_id]);

    // Avoid duplicates
    $stmt = $pdo->prepare("
        SELECT id FROM notifications
        WHERE user_id = :them AND actor_id = :me AND type = 'follow'
    ");
    $stmt->execute([':them' => $target_id, ':me' => $my_id]);

    if (!$stmt->fetch()) {
    // Check recipient's preferences
    $stmt = $pdo->prepare("
        SELECT notify_follows, notify_paused
        FROM users WHERE id = :id
    ");
    $stmt->execute([':id' => $target_id]);
    $prefs = $stmt->fetch();

    if ($prefs && $prefs['notify_follows'] && !$prefs['notify_paused']) {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, actor_id, type)
            VALUES (:them, :me, 'follow')
        ");
        $stmt->execute([':them' => $target_id, ':me' => $my_id]);
    }
}
    $new_state = 'followed';
}

// Return JSON for AJAX
if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'state'   => $new_state,
    ]);
    exit;
}

// Fallback for non-AJAX: redirect
$redirect = $_POST['redirect'] ?? 'discover.php';
$allowed = ['discover.php', 'profile.php', 'search.php', 'discover_feed.php'];
$redirect_base = strtok($redirect, '?');
if (!in_array($redirect_base, $allowed, true)) {
    $redirect = 'discover.php';
}

header("Location: $redirect");
exit;