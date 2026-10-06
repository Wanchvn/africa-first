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
$query = trim($_GET['q'] ?? '');

$users = [];
$posts = [];

if ($query !== '' && mb_strlen($query) >= 2) {
    $like = '%' . $query . '%';

    $stmt = $pdo->prepare("
        SELECT
            users.id, users.username, users.avatar,
            (SELECT 1 FROM follows WHERE follower_id = :me_follow AND following_id = users.id) AS is_following
        FROM users
        WHERE users.username LIKE :like
          AND users.id != :me
        ORDER BY users.username ASC
        LIMIT 20
    ");
    $stmt->execute([
        ':like' => $like,
        ':me' => $user_id,
        ':me_follow' => $user_id,
    ]);
    $users = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT
            posts.id, posts.content, posts.media_path, posts.created_at,
            users.username, users.avatar, users.id AS author_id,
            (SELECT COUNT(*) FROM likes WHERE post_id = posts.id) AS like_count,
            (SELECT COUNT(*) FROM likes WHERE post_id = posts.id AND user_id = :me_like) AS liked_by_me,
            (SELECT COUNT(*) FROM comments WHERE post_id = posts.id) AS comment_count
        FROM posts
        INNER JOIN users ON posts.user_id = users.id
        WHERE posts.content LIKE :like
        ORDER BY posts.created_at DESC
        LIMIT 30
    ");
    $stmt->execute([':like' => $like, ':me_like' => $user_id]);
    $posts = $stmt->fetchAll();
}

$has_results = !empty($users) || !empty($posts);
$show_empty = ($query !== '' && mb_strlen($query) >= 2 && !$has_results);
$query_too_short = ($query !== '' && mb_strlen($query) < 2);

$page_title = __('search_title');
require 'includes/header.php';
?>

<h1><?= __('search_title') ?></h1>

<div class="card">
    <form method="GET" class="stack">
        <input type="text" name="q" value="<?= htmlspecialchars($query) ?>"
               placeholder="<?= __('search_placeholder') ?>"
               autofocus>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button type="submit"><?= __('search_button') ?></button>
            <?php if ($query !== ''): ?>
                <a href="search.php" class="btn-secondary"><?= __('search_clear') ?></a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($query_too_short): ?>
    <div class="message"><?= __('search_too_short') ?></div>
<?php endif; ?>

<?php if ($show_empty): ?>
    <div class="empty"><?= __('search_no_results') ?></div>
<?php endif; ?>

<?php if (!empty($users)): ?>
    <h2><?= __('search_people') ?> (<?= count($users) ?>)</h2>
    <?php foreach ($users as $u): ?>
        <div class="user-row">
            <div style="display:flex; align-items:center; gap:12px;">
                <?php if ($u['avatar']): ?>
                    <img class="avatar avatar-small"
                         src="<?= htmlspecialchars($u['avatar']) ?>"
                         alt="">
                <?php else: ?>
                    <div class="avatar avatar-small avatar-placeholder">
                        <?= strtoupper(substr($u['username'], 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <a href="profile.php?u=<?= urlencode($u['username']) ?>" class="name">
                    <?= htmlspecialchars($u['username']) ?>
                </a>
            </div>
            <form method="POST" action="follow.php">
                <?= csrf_field() ?>
                <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                <input type="hidden" name="redirect" value="search.php?q=<?= urlencode($query) ?>">
                <button type="submit" class="<?= $u['is_following'] ? 'btn-secondary' : '' ?>">
                    <?= $u['is_following'] ? __('profile_unfollow') : __('profile_follow') ?>
                </button>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($posts)): ?>
    <h2><?= __('search_posts') ?> (<?= count($posts) ?>)</h2>
    <?php foreach ($posts as $post): ?>
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
                    <input type="hidden" name="redirect" value="search.php?q=<?= urlencode($query) ?>">
                    <button type="submit" class="like-btn <?= $post['liked_by_me'] ? 'liked' : '' ?>">
                        <?= $post['liked_by_me'] ? '♥' : '♡' ?>
                        <?= (int)$post['like_count'] ?>
                    </button>
                </form>
                <span class="comment-count">💬 <?= (int)$post['comment_count'] ?></span>
                <?php if ($post['author_id'] !== $user_id): ?>
                    <a href="report.php?post_id=<?= $post['id'] ?>" class="report-link">
                        <?= __('post_report') ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($has_results): ?>
    <div class="end-of-feed">
        <strong>End of results</strong>
        Try a different search term to find more.
    </div>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>