<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/posts.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error(405, 'Method not allowed.');

$user = api_require_admin($pdo);
$me = (int)$user['id'];

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$post_id = (int)($data['post_id'] ?? 0);

if ($post_id <= 0) api_error(400, 'Invalid post ID.');

// The existing delete_post() function handles ownership verification and file cleanup
$ok = delete_post($pdo, $post_id, $me);

if ($ok) {
    api_ok(['message' => 'Post deleted.']);
} else {
    api_error(403, 'Could not delete post. You may not have permission.');
}