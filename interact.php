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
    header('Location: feed.php');
    exit;
}

csrf_verify();

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$user_id = (int)$_SESSION['user_id'];
$action  = $_POST['action'] ?? '';
$post_id = (int)($_POST['post_id'] ?? 0);
$redirect = $_POST['redirect'] ?? 'feed.php';

$allowed_redirects = ['feed.php', 'profile.php', 'search.php', 'discover_feed.php', 'topic.php', 'saved.php'];
$redirect_base = strtok($redirect, '?');
if (!in_array($redirect_base, $allowed_redirects, true)) {
    $redirect = 'feed.php';
}

if ($post_id <= 0) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid post ID']);
        exit;
    }
    header("Location: $redirect");
    exit;
}

$stmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = :id");
$stmt->execute([':id' => $post_id]);
$post_owner = $stmt->fetchColumn();

if ($post_owner === false) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Post not found']);
        exit;
    }
    header("Location: $redirect");
    exit;
}

$post_owner = (int)$post_owner;

if ($action === 'like') {
    rate_limit_enforce($pdo, client_ip(), 'like', 60, 60);

    $stmt = $pdo->prepare("SELECT id FROM likes WHERE user_id = :u AND post_id = :p");
    $stmt->execute([':u' => $user_id, ':p' => $post_id]);

    if ($stmt->fetch()) {
        $stmt = $pdo->prepare("DELETE FROM likes WHERE user_id = :u AND post_id = :p");
        $stmt->execute([':u' => $user_id, ':p' => $post_id]);

        $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = :owner AND actor_id = :actor AND type = 'like' AND post_id = :p");
        $stmt->execute([':owner' => $post_owner, ':actor' => $user_id, ':p' => $post_id]);

        $new_liked = false;
    } else {
        $stmt = $pdo->prepare("INSERT INTO likes (user_id, post_id) VALUES (:u, :p)");
        $stmt->execute([':u' => $user_id, ':p' => $post_id]);

        if ($post_owner !== $user_id) {
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

        $new_liked = true;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = :p");
    $stmt->execute([':p' => $post_id]);
    $new_count = (int)$stmt->fetchColumn();

    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success'    => true,
            'liked'      => $new_liked,
            'like_count' => $new_count,
        ]);
        exit;
    }
} elseif ($action === 'comment') {
    rate_limit_enforce($pdo, client_ip(), 'comment', 10, 60);

    $content = trim($_POST['content'] ?? '');
    if ($content !== '' && mb_strlen($content) <= 500) {
        $stmt = $pdo->prepare("INSERT INTO comments (user_id, post_id, content) VALUES (:u, :p, :c)");
        $stmt->execute([':u' => $user_id, ':p' => $post_id, ':c' => $content]);

        if ($post_owner !== $user_id) {
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, actor_id, type, post_id)
                VALUES (:owner, :actor, 'comment', :p)
            ");
            $stmt->execute([':owner' => $post_owner, ':actor' => $user_id, ':p' => $post_id]);
        }
    }
} elseif ($action === 'bookmark') {
    rate_limit_enforce($pdo, client_ip(), 'bookmark', 60, 60);

    $stmt = $pdo->prepare("SELECT id FROM bookmarks WHERE user_id = :u AND post_id = :p");
    $stmt->execute([':u' => $user_id, ':p' => $post_id]);

    if ($stmt->fetch()) {
        $stmt = $pdo->prepare("DELETE FROM bookmarks WHERE user_id = :u AND post_id = :p");
        $stmt->execute([':u' => $user_id, ':p' => $post_id]);
        $bookmarked = false;
    } else {
        $stmt = $pdo->prepare("INSERT INTO bookmarks (user_id, post_id) VALUES (:u, :p)");
        $stmt->execute([':u' => $user_id, ':p' => $post_id]);
        $bookmarked = true;
    }

    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success'    => true,
            'bookmarked' => $bookmarked,
        ]);
        exit;
    }
}

header("Location: $redirect");
exit;