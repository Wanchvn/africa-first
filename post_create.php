<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/posts.php';
require_once __DIR__ . '/includes/csrf.php';

// Must be logged in
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Must be POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: feed.php');
    exit;
}

// CSRF check (your csrf_verify() reads $_POST['csrf_token'])
csrf_verify();

try {
    $body       = trim($_POST['body'] ?? '');
    $voice      = $_FILES['voice'] ?? null;
    $duration   = (int)($_POST['voice_duration'] ?? 0);
    $visibility = $_POST['visibility'] ?? 'public';

    $hasVoice = $voice && ($voice['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($body === '' && !$hasVoice) {
        throw new RuntimeException('Post cannot be empty.');
    }

    $postId = create_post(
        $pdo,
        (int)$_SESSION['user_id'],
        $body,
        $voice,
        $duration,
        $visibility
    );

    header('Location: feed.php?posted=' . $postId);
    exit;

} catch (Throwable $e) {
    $_SESSION['flash_error'] = $e->getMessage();
    header('Location: feed.php');
    exit;
}