<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require_once __DIR__ . '/includes/posts.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: feed.php');
    exit;
}

csrf_verify();
rate_limit_enforce($pdo, client_ip(), 'delete_post', 20, 3600);

$user_id = (int)$_SESSION['user_id'];
$post_id = (int)($_POST['post_id'] ?? 0);

// Detect AJAX
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Redirect (fallback for non-AJAX)
$redirect = $_POST['redirect'] ?? 'profile.php';
$allowed_redirects = ['feed.php', 'profile.php', 'search.php', 'discover_feed.php', 'topic.php', 'saved.php'];
$redirect_base = strtok($redirect, '?');
if (!in_array($redirect_base, $allowed_redirects, true)) {
    $redirect = 'profile.php';
}

if ($post_id <= 0) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid post.']);
        exit;
    }
    $_SESSION['flash_error'] = 'Invalid post.';
    header('Location: ' . $redirect);
    exit;
}

try {
    $ok = delete_post($pdo, $post_id, $user_id);

    if ($is_ajax) {
        header('Content-Type: application/json');
        if ($ok) {
            echo json_encode(['success' => true, 'post_id' => $post_id]);
        } else {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Post not found or you don\'t have permission to delete it.']);
        }
        exit;
    }

    if (!$ok) {
        $_SESSION['flash_error'] = 'Post not found or you don\'t have permission to delete it.';
    } else {
        $_SESSION['flash_success'] = 'Post deleted.';
    }
} catch (Throwable $e) {
    error_log('delete_post error: ' . $e->getMessage());

    if ($is_ajax) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Could not delete the post. Please try again.']);
        exit;
    }
    $_SESSION['flash_error'] = 'Could not delete the post. Please try again.';
}

header('Location: ' . $redirect);
exit;