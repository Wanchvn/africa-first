<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';

$user = api_require_admin($pdo);
$me = (int)$user['id'];

$stmt = $pdo->prepare("
    SELECT t.id, t.name, t.slug, t.description,
           (SELECT COUNT(*) FROM topic_members tm WHERE tm.topic_id = t.id) AS member_count,
           (SELECT COUNT(*) FROM topic_members tm WHERE tm.topic_id = t.id AND tm.user_id = :me) AS is_member
    FROM topics t
    ORDER BY member_count DESC
    LIMIT 50
");
$stmt->execute([':me' => $me]);
$topics = $stmt->fetchAll(PDO::FETCH_ASSOC);

$formatted = [];
foreach ($topics as $t) {
    $formatted[] = [
        'id' => (int)$t['id'],
        'name' => $t['name'],
        'slug' => $t['slug'],
        'description' => $t['description'],
        'members' => (int)$t['member_count'],
        'is_member' => (bool)$t['is_member']
    ];
}

api_ok(['topics' => $formatted]);