<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Fetch bookmarked posts, newest bookmark first
$stmt = $pdo->prepare("
    SELECT
        posts.id, posts.content, posts.media_path, posts.created_at,
        users.username, users.display_name, users.avatar, users.id AS author_id,
        bookmarks.created_at AS bookmarked_at,
        (SELECT COUNT(*) FROM likes WHERE post_id = posts.id) AS like_count,
        (SELECT COUNT(*) FROM likes WHERE post_id = posts.id AND user_id = :me_like) AS liked_by_me,
        (SELECT COUNT(*) FROM comments WHERE post_id = posts.id) AS comment_count
    FROM bookmarks
    INNER JOIN posts ON bookmarks.post_id = posts.id
    INNER JOIN users ON posts.user_id = users.id
    WHERE bookmarks.user_id = :me
    ORDER BY bookmarks.created_at DESC
");
$stmt->execute([':me' => $user_id, ':me_like' => $user_id]);
$posts = $stmt->fetchAll();

// Comments for these posts
$comments_by_post = [];
if (!empty($posts)) {
    $post_ids = array_column($posts, 'id');
    $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT comments.post_id, comments.content, comments.created_at,
               users.username, users.display_name, users.avatar
        FROM comments
        INNER JOIN users ON comments.user_id = users.id
        WHERE comments.post_id IN ($placeholders)
        ORDER BY comments.created_at ASC
    ");
    $stmt->execute($post_ids);
    foreach ($stmt->fetchAll() as $c) {
        $comments_by_post[$c['post_id']][] = $c;
    }
}

// All saved posts are (obviously) bookmarked by this user
$my_bookmarks = array_column($posts, 'id');

$page_title = 'Saved posts';
require 'includes/header.php';
?>

<h1>Saved posts</h1>
<p style="color: var(--muted); margin-bottom: var(--space-5);">
    Posts you've bookmarked. Only you can see this page.
</p>

<?php if (empty($posts)): ?>
    <div class="empty">
        <strong>No saved posts yet</strong>
        Tap the bookmark icon on any post to save it here for later.
    </div>
<?php else: ?>
    <?php foreach ($posts as $post): ?>
        <?php $author_name = $post['display_name'] ?: $post['username']; ?>
        <div class="card">
            <div class="post-header">
                <?php if ($post['avatar']): ?>
                    <img class="avatar avatar-small" src="<?= htmlspecialchars($post['avatar']) ?>" alt="">
                <?php else: ?>
                    <div class="avatar avatar-small avatar-placeholder">
                        <?= strtoupper(substr($author_name, 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <div class="author">
                    <a href="profile.php?u=<?= urlencode($post['username']) ?>">
                        <?= htmlspecialchars($author_name) ?>
                    </a>
                </div>
                <div class="meta" style="margin-left:auto;font-size:0.8rem;">
                    Saved <?= htmlspecialchars(date('M j', strtotime($post['bookmarked_at']))) ?>
                </div>
            </div>

            <div class="content"><?= htmlspecialchars($post['content']) ?></div>

            <?php if ($post['media_path']): ?>
                <img class="media" src="<?= htmlspecialchars($post['media_path']) ?>" alt="Post image">
            <?php endif; ?>

            <div class="meta"><?= htmlspecialchars($post['created_at']) ?></div>

            <div class="actions">
                <form method="POST" action="interact.php" class="like-form" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="like">
                    <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                    <input type="hidden" name="redirect" value="saved.php">
                    <button type="submit" class="like-btn <?= $post['liked_by_me'] ? 'liked' : '' ?>">
                        <span class="like-heart"><?= $post['liked_by_me'] ? '♥' : '♡' ?></span>
                        <span class="like-count"><?= (int)$post['like_count'] ?></span>
                    </button>
                </form>
                <span class="comment-count">
                    <i data-lucide="message-circle" style="width:14px;height:14px;"></i>
                    <?= (int)$post['comment_count'] ?>
                </span>
                <form method="POST" action="interact.php" class="bookmark-form" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="bookmark">
                    <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                    <input type="hidden" name="redirect" value="saved.php">
                    <button type="submit" class="bookmark-btn bookmarked">
                        <i data-lucide="bookmark" class="bookmark-icon"></i>
                    </button>
                </form>
            </div>

            <?php if (!empty($comments_by_post[$post['id']])): ?>
                <div class="comments">
                    <?php foreach ($comments_by_post[$post['id']] as $c): ?>
                        <?php $comment_name = $c['display_name'] ?: $c['username']; ?>
                        <div class="comment">
                            <div class="comment-header">
                                <?php if (!empty($c['avatar'])): ?>
                                    <img class="avatar avatar-tiny" src="<?= htmlspecialchars($c['avatar']) ?>" alt="">
                                <?php else: ?>
                                    <div class="avatar avatar-tiny avatar-placeholder">
                                        <?= strtoupper(substr($comment_name, 0, 1)) ?>
                                    </div>
                                <?php endif; ?>
                                <strong><?= htmlspecialchars($comment_name) ?></strong>
                            </div>
                            <?= htmlspecialchars($c['content']) ?>
                            <div class="meta"><?= htmlspecialchars($c['created_at']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>