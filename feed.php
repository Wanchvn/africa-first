<?php
session_start();
require 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT posts.id, posts.content, posts.media_path, posts.created_at,
           users.username, users.id AS author_id
    FROM posts
    INNER JOIN follows ON posts.user_id = follows.following_id
    INNER JOIN users ON posts.user_id = users.id
    WHERE follows.follower_id = :me
    ORDER BY posts.created_at DESC
    LIMIT 50
");
$stmt->execute([':me' => $_SESSION['user_id']]);
$feed = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head><title>Feed — Baobab</title></head>
<body>
<h1>Your Feed</h1>
<p>
    <a href="perfil.php">My Profile</a> |
    <a href="descubrir.php">Discover People</a> |
    <a href="logout.php">Log out</a>
</p>

<?php if (empty($feed)): ?>
    <p>Your feed is empty. <a href="descubrir.php">Find people to follow</a>.</p>
<?php else: ?>
    <?php foreach ($feed as $post): ?>
        <div style="border:1px solid #ccc; padding:8px; margin:8px 0;">
            <strong>
                <a href="perfil.php?id=<?= $post['author_id'] ?>">
                    <?= htmlspecialchars($post['username']) ?>
                </a>
            </strong>
            <p><?= nl2br(htmlspecialchars($post['content'])) ?></p>
            <?php if ($post['media_path']): ?>
                <img src="<?= htmlspecialchars($post['media_path']) ?>"
                     style="max-width: 400px; display: block;"
                     alt="Post image">
            <?php endif; ?>
            <small><?= htmlspecialchars($post['created_at']) ?></small>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
</body>
</html>