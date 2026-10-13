<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require_once __DIR__ . '/includes/blocks.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

csrf_verify();

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$my_id     = (int)$_SESSION['user_id'];
$target_id = (int)($_POST['target_id'] ?? 0);

$redirect = $_POST['redirect'] ?? 'discover.php';
$allowed = ['discover.php', 'profile.php', 'search.php', 'discover_feed.php', 'feed.php'];
$redirect_base = strtok($redirect, '?');
if (!in_array($redirect_base, $allowed, true)) {
    $redirect = 'discover.php';
}

// ---- Validate target ----
if ($target_id <= 0 || $target_id === $my_id) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid target']);
        exit;
    }
    header('Location: ' . $redirect);
    exit;
}

// ---- Block gate ----
if (is_blocked_either($pdo, $my_id, $target_id)) {
    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => __('block_error_cannot_follow')]);
    } else {
        $_SESSION['flash'] = __('block_error_cannot_follow');
        header('Location: ' . $redirect);
    }
    exit;
}

// ---- Verify target user exists ----
$stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id");
$stmt->execute([':id' => $target_id]);
if (!$stmt->fetch()) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    header('Location: ' . $redirect);
    exit;
}

rate_limit_enforce($pdo, client_ip(), 'follow', 30, 300);

// ---- Toggle follow ----
$stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = :me AND following_id = :them");
$stmt->execute([':me' => $my_id, ':them' => $target_id]);

if ($stmt->fetch()) {
    // Unfollow
    $pdo->prepare("DELETE FROM follows WHERE follower_id = :me AND following_id = :them")
        ->execute([':me' => $my_id, ':them' => $target_id]);

    // Remove any pending follow notification
    $pdo->prepare("
        DELETE FROM notifications
        WHERE user_id = :them AND actor_id = :me AND type = 'follow'
    ")->execute([':them' => $target_id, ':me' => $my_id]);

    $new_state = 'unfollowed';
} else {
    // Follow
    $pdo->prepare("INSERT INTO follows (follower_id, following_id) VALUES (:me, :them)")
        ->execute([':me' => $my_id, ':them' => $target_id]);

    // Check recipient's preferences and avoid duplicates
    $stmt = $pdo->prepare("
        SELECT notify_follows, notify_paused FROM users WHERE id = :id
    ");
    $stmt->execute([':id' => $target_id]);
    $prefs = $stmt->fetch();

    if ($prefs && $prefs['notify_follows'] && !$prefs['notify_paused']) {
        $stmt = $pdo->prepare("
            SELECT id FROM notifications
            WHERE user_id = :them AND actor_id = :me AND type = 'follow'
        ");
        $stmt->execute([':them' => $target_id, ':me' => $my_id]);

        if (!$stmt->fetch()) {
            $pdo->prepare("
                INSERT INTO notifications (user_id, actor_id, type)
                VALUES (:them, :me, 'follow')
            ")->execute([':them' => $target_id, ':me' => $my_id]);
        }
    }

    $new_state = 'followed';
}

// ---- Respond ----
if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'state' => $new_state]);
    exit;
}

header('Location: ' . $redirect);
exit;