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
            users.id, users.username, users.display_name, users.avatar,
            (SELECT 1 FROM follows WHERE follower_id = :me_follow AND following_id = users.id) AS is_following
        FROM users
        WHERE (users.username LIKE :like OR users.display_name LIKE :like2)
          AND users.id != :me
        ORDER BY users.username ASC
        LIMIT 20
    ");
    $stmt->execute([
        ':like' => $like,
        ':like2' => $like,
        ':me' => $user_id,
        ':me_follow' => $user_id,
    ]);
    $users = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT
            posts.id, posts.content, posts.media_path, posts.created_at, posts.edited_at,
            users.username, users.display_name, users.avatar, users.id AS author_id,
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

// Fetch user's bookmarked post IDs
$stmt = $pdo->prepare("SELECT post_id FROM bookmarks WHERE user_id = :me");
$stmt->execute([':me' => $user_id]);
$my_bookmarks = array_column($stmt->fetchAll(), 'post_id');

$has_results = !empty($users) || !empty($posts);
$show_empty = ($query !== '' && mb_strlen($query) >= 2 && !$has_results);
$query_too_short = ($query !== '' && mb_strlen($query) < 2);

$page_title = __('search_title');
require 'includes/header.php';
?>

<h1><?= __('search_title') ?></h1>

<?php if ($query !== ''): ?>
    <div class="search-context">
        <span class="search-context-label">Results for</span>
        <strong class="search-context-query">"<?= htmlspecialchars($query) ?>"</strong>
        <a href="search.php" class="search-clear-link">Clear</a>
    </div>
<?php endif; ?>

<?php if ($query_too_short): ?>
    <div class="message"><?= __('search_too_short') ?></div>
<?php endif; ?>

<?php if ($show_empty): ?>
    <div class="empty"><?= __('search_no_results') ?></div>
<?php endif; ?>

<?php if (!empty($users)): ?>
    <h2><?= __('search_people') ?> (<?= count($users) ?>)</h2>
    <?php foreach ($users as $u): ?>
        <?php $user_name = $u['display_name'] ?: $u['username']; ?>
        <div class="user-row">
            <div style="display:flex; align-items:center; gap:12px;">
                <?php if ($u['avatar']): ?>
                    <img class="avatar avatar-small" src="<?= htmlspecialchars($u['avatar']) ?>" alt="">
                <?php else: ?>
                    <div class="avatar avatar-small avatar-placeholder">
                        <?= strtoupper(substr($user_name, 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <a href="profile.php?u=<?= urlencode($u['username']) ?>" class="name" data-user-id="<?= $u['id'] ?>">
    <?= htmlspecialchars($user_name) ?>
</a>
            </div>
            <form method="POST" action="follow.php" class="follow-form">
                <?= csrf_field() ?>
                <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                <input type="hidden" name="redirect" value="search.php?q=<?= urlencode($query) ?>">
                <button type="submit"
                        class="<?= $u['is_following'] ? 'btn-secondary' : '' ?>"
                        data-follow-text="<?= __('profile_follow') ?>"
                        data-unfollow-text="<?= __('profile_unfollow') ?>">
                    <?= $u['is_following'] ? __('profile_unfollow') : __('profile_follow') ?>
                </button>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($posts)): ?>
    <h2><?= __('search_posts') ?> (<?= count($posts) ?>)</h2>
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
                    <input type="hidden" name="redirect" value="search.php?q=<?= urlencode($query) ?>">
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
                    <input type="hidden" name="redirect" value="search.php?q=<?= urlencode($query) ?>">
                    <button type="submit" class="bookmark-btn <?= in_array($post['id'], $my_bookmarks) ? 'bookmarked' : '' ?>">
                        <i data-lucide="bookmark" class="bookmark-icon"></i>
                    </button>
                </form>
                <?php if ((int)$post['author_id'] === $user_id): ?>
                    <a href="edit_post.php?id=<?= $post['id'] ?>&from=<?= urlencode('search.php?q=' . $query) ?>" class="edit-link">
                        <i data-lucide="pencil" style="width:14px;height:14px;"></i>
                        Edit
                    </a>
                <?php else: ?>
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