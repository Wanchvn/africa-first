<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';

$user = api_require_admin($pdo);

$post_id = (int)($_GET['post_id'] ?? 0);
if ($post_id <= 0) {
    api_error(400, 'Invalid post ID.');
}

$stmt = $pdo->prepare("
    SELECT c.id, c.content, c.created_at, 
           u.id AS user_id, u.username, u.display_name, u.avatar
    FROM comments c
    INNER JOIN users u ON u.id = c.user_id
    WHERE c.post_id = :p
    ORDER BY c.created_at ASC
");
$stmt->execute([':p' => $post_id]);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$formatted = [];
foreach ($comments as $c) {
    $formatted[] = [
        'id' => (int)$c['id'],
        'content' => $c['content'],
        'created_at' => $c['created_at'],
        'author' => [
            'id' => (int)$c['user_id'],
            'name' => $c['display_name'] ?: $c['username'],
            'username' => $c['username'],
            'avatar' => $c['avatar']
        ]
    ];
}

api_ok(['comments' => $formatted]);