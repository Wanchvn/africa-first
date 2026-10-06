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

$stmt = $pdo->prepare("
    SELECT
        posts.id, posts.content, posts.media_path, posts.created_at,
        users.username, users.avatar, users.id AS author_id,
        (SELECT COUNT(*) FROM likes WHERE post_id = posts.id) AS like_count,
        (SELECT COUNT(*) FROM likes WHERE post_id = posts.id AND user_id = :me_like) AS liked_by_me,
        (SELECT COUNT(*) FROM comments WHERE post_id = posts.id) AS comment_count
    FROM posts
    INNER JOIN follows ON posts.user_id = follows.following_id
    INNER JOIN users ON posts.user_id = users.id
    WHERE follows.follower_id = :me
    ORDER BY posts.created_at DESC
    LIMIT 50
");
$stmt->execute([':me' => $user_id, ':me_like' => $user_id]);
$feed = $stmt->fetchAll();

$post_ids = array_column($feed, 'id');
$comments_by_post = [];
if (!empty($post_ids)) {
    $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT comments.id, comments.post_id, comments.content, comments.created_at,
               users.username, users.avatar
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

$page_title = __('feed_title');
require 'includes/header.php';
?>

<h1><?= __('feed_title') ?></h1>

<?php if (empty($feed)): ?>
    <div class="empty">
        <?= __('feed_empty') ?> <a href="discover.php"><?= __('feed_find_people') ?></a>.
    </div>
<?php else: ?>
    <?php foreach ($feed as $post): ?>
        <div class="card">
            <div class="post-header">
                <?php if ($post['avatar']): ?>
                    <img class="avatar avatar-small"
                         src="<?= htmlspecialchars($post['avatar']) ?>"
                         alt="">
                <?php else: ?>
                    <div class="avatar avatar-small avatar-placeholder">
                        <?= strtoupper(substr($post['username'], 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <div class="author">
                    <a href="profile.php?u=<?= urlencode($post['username']) ?>">
                        <?= htmlspecialchars($post['username']) ?>
                    </a>
                </div>
            </div>

            <div class="content"><?= htmlspecialchars($post['content']) ?></div>

            <?php if ($post['media_path']): ?>
                <img class="media" src="<?= htmlspecialchars($post['media_path']) ?>" alt="Post image">
            <?php endif; ?>

            <div class="meta"><?= htmlspecialchars($post['created_at']) ?></div>

            <div class="actions">
                <form method="POST" action="interact.php" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="like">
                    <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                    <input type="hidden" name="redirect" value="feed.php">
                    <button type="submit" class="like-btn <?= $post['liked_by_me'] ? 'liked' : '' ?>">
                        <?= $post['liked_by_me'] ? '♥' : '♡' ?>
                        <?= (int)$post['like_count'] ?>
                    </button>
                </form>
                <span class="comment-count">💬 <?= (int)$post['comment_count'] ?></span>
                <?php if ($post['author_id'] !== (int)$_SESSION['user_id']): ?>
                    <a href="report.php?post_id=<?= $post['id'] ?>" class="report-link"><?= __('post_report') ?></a>
                <?php endif; ?>
            </div>

            <?php if (!empty($comments_by_post[$post['id']])): ?>
                <div class="comments">
                    <?php foreach ($comments_by_post[$post['id']] as $c): ?>
                        <div class="comment">
                            <div class="comment-header">
                                <?php if (!empty($c['avatar'])): ?>
                                    <img class="avatar avatar-tiny"
                                         src="<?= htmlspecialchars($c['avatar']) ?>"
                                         alt="">
                                <?php else: ?>
                                    <div class="avatar avatar-tiny avatar-placeholder">
                                        <?= strtoupper(substr($c['username'], 0, 1)) ?>
                                    </div>
                                <?php endif; ?>
                                <strong><?= htmlspecialchars($c['username']) ?></strong>
                            </div>
                            <?= htmlspecialchars($c['content']) ?>
                            <div class="meta"><?= htmlspecialchars($c['created_at']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="interact.php" class="comment-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="comment">
                <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                <input type="hidden" name="redirect" value="feed.php">
                <input type="text" name="content" placeholder="<?= __('post_comment_placeholder') ?>" maxlength="500" required>
                <button type="submit"><?= __('post_send') ?></button>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>