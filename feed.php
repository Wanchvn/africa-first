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

$page_title = 'Feed';
require 'includes/header.php';
?>

<h1>Your Feed</h1>

<?php if (empty($feed)): ?>
    <div class="empty">
        Your feed is empty. <a href="descubrir.php">Find people to follow</a>.
    </div>
<?php else: ?>
    <?php foreach ($feed as $post): ?>
        <div class="card">
            <div class="author">
                <a href="perfil.php?id=<?= $post['author_id'] ?>">
                    <?= htmlspecialchars($post['username']) ?>
                </a>
            </div>
            <div class="content"><?= htmlspecialchars($post['content']) ?></div>
            <?php if ($post['media_path']): ?>
                <img class="media" src="<?= htmlspecialchars($post['media_path']) ?>" alt="Post image">
            <?php endif; ?>
            <div class="meta"><?= htmlspecialchars($post['created_at']) ?></div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>