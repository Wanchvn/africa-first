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
        posts.id, posts.content, posts.media_path, posts.created_at, posts.edited_at,
        users.username, users.avatar, users.display_name, users.id AS author_id,
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

// Fetch user's bookmarked post IDs
$stmt = $pdo->prepare("SELECT post_id FROM bookmarks WHERE user_id = :me");
$stmt->execute([':me' => $user_id]);
$my_bookmarks = array_column($stmt->fetchAll(), 'post_id');

$post_ids = array_column($feed, 'id');
$comments_by_post = [];
if (!empty($post_ids)) {
    $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT comments.id, comments.post_id, comments.content, comments.created_at,
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
            </div>

            <div class="content"><?= htmlspecialchars($post['content']) ?></div>

            <?php if ($post['media_path']): ?>
                <img class="media" src="<?= htmlspecialchars($post['media_path']) ?>" alt="Post image">
            <?php endif; ?>

            <div class="meta">
                <?= htmlspecialchars($post['created_at']) ?>
                <?php if (!empty($post['edited_at'])): ?>
                    · <span class="edited-label" title="Edited <?= htmlspecialchars($post['edited_at']) ?>">Edited</span>
                <?php endif; ?>
            </div>

            <div class="actions">
                <form method="POST" action="interact.php" class="like-form" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="like">
                    <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                    <input type="hidden" name="redirect" value="feed.php">
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
                    <input type="hidden" name="redirect" value="feed.php">
                    <button type="submit" class="bookmark-btn <?= in_array($post['id'], $my_bookmarks) ? 'bookmarked' : '' ?>">
                        <i data-lucide="bookmark" class="bookmark-icon"></i>
                    </button>
                </form>
                <?php if ((int)$post['author_id'] === $user_id): ?>
                    <a href="edit_post.php?id=<?= $post['id'] ?>&from=feed.php" class="edit-link">
                        <i data-lucide="pencil" style="width:14px;height:14px;"></i>
                        Edit
                    </a>
                <?php else: ?>
                    <a href="report.php?post_id=<?= $post['id'] ?>" class="report-link"><?= __('post_report') ?></a>
                <?php endif; ?>
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

    <div class="end-of-feed">
        <strong>You're all caught up</strong>
        There are no more posts from people you follow.
    </div>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>