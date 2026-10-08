<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/topics.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$slug = trim($_GET['slug'] ?? '');

if ($slug === '') {
    header('Location: topics.php');
    exit;
}

$topic = get_topic_by_slug($pdo, $slug);
if (!$topic) {
    header('Location: topics.php');
    exit;
}

$topic_id = (int)$topic['id'];
$is_curator = ((int)$topic['curator_id'] === $user_id);
$is_member = is_topic_member($pdo, $topic_id, $user_id);

$member_count = topic_member_count($pdo, $topic_id);
$post_count = topic_post_count($pdo, $topic_id);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_verify();
    $action = $_POST['action'];

    if ($action === 'join' && !$is_member) {
        $stmt = $pdo->prepare("INSERT INTO topic_members (topic_id, user_id) VALUES (:t, :u)");
        $stmt->execute([':t' => $topic_id, ':u' => $user_id]);
        $is_member = true;
        $member_count++;
        $message = 'You joined #' . $topic['name'];
    } elseif ($action === 'leave' && $is_member && !$is_curator) {
        $stmt = $pdo->prepare("DELETE FROM topic_members WHERE topic_id = :t AND user_id = :u");
        $stmt->execute([':t' => $topic_id, ':u' => $user_id]);
        $is_member = false;
        $member_count--;
        $message = 'You left #' . $topic['name'];
    }
}

$stmt = $pdo->prepare("
    SELECT
        posts.id, posts.content, posts.media_path, posts.created_at, posts.edited_at,
        users.username, users.display_name, users.avatar, users.id AS author_id,
        (SELECT COUNT(*) FROM likes WHERE post_id = posts.id) AS like_count,
        (SELECT COUNT(*) FROM likes WHERE post_id = posts.id AND user_id = :me_like) AS liked_by_me,
        (SELECT COUNT(*) FROM comments WHERE post_id = posts.id) AS comment_count
    FROM posts
    INNER JOIN post_topics ON post_topics.post_id = posts.id
    INNER JOIN users ON posts.user_id = users.id
    WHERE post_topics.topic_id = :topic_id
    ORDER BY posts.created_at DESC
    LIMIT 50
");
$stmt->execute([':topic_id' => $topic_id, ':me_like' => $user_id]);
$posts = $stmt->fetchAll();

// Fetch user's bookmarked post IDs
$stmt = $pdo->prepare("SELECT post_id FROM bookmarks WHERE user_id = :me");
$stmt->execute([':me' => $user_id]);
$my_bookmarks = array_column($stmt->fetchAll(), 'post_id');

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

$curator_name = $topic['curator_display_name'] ?: $topic['curator_username'];
$page_title = '#' . $topic['name'];
require 'includes/header.php';
?>

<div style="margin-bottom: var(--space-5);">
    <a href="topics.php" style="color: var(--muted); font-size: 0.9rem;">← All topics</a>
    <h1 style="margin: var(--space-2) 0;">#<?= htmlspecialchars($topic['name']) ?></h1>
    <?php if ($topic['description']): ?>
        <p style="color: var(--muted); font-size: 1rem; margin-bottom: var(--space-3);">
            <?= htmlspecialchars($topic['description']) ?>
        </p>
    <?php endif; ?>
    <div class="profile-stats">
        <strong><?= (int)$member_count ?></strong> <?= $member_count == 1 ? 'member' : 'members' ?>
        <span class="stat-dot">·</span>
        <strong><?= (int)$post_count ?></strong> <?= $post_count == 1 ? 'post' : 'posts' ?>
        <span class="stat-dot">·</span>
        Curated by
        <a href="profile.php?u=<?= urlencode($topic['curator_username']) ?>">
            <?= htmlspecialchars($curator_name) ?>
        </a>
    </div>

    <div style="margin-top: var(--space-4); display:flex; gap:10px; flex-wrap:wrap;">
        <?php if ($is_curator): ?>
            <span class="btn-secondary btn-small" style="cursor:default;">✓ You curate this topic</span>
            <a href="edit_topic.php?slug=<?= urlencode($topic['slug']) ?>" class="btn-secondary btn-small">Edit topic</a>
        <?php elseif ($is_member): ?>
            <form method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="leave">
                <button type="submit" class="btn-secondary btn-small">Leave topic</button>
            </form>
        <?php else: ?>
            <form method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="join">
                <button type="submit" class="btn">Join topic</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
    <div class="message" style="background: #E8F5E9; border-left-color: #4CAF50;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<?php if (empty($posts)): ?>
    <div class="empty">
        <strong>No posts in this topic yet</strong>
        When you post something on your profile, tag it with #<?= htmlspecialchars($topic['name']) ?> to add it here.
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
                <?php if ($is_curator && (int)$post['author_id'] !== $user_id): ?>
                    <form method="POST" action="remove_from_topic.php" style="margin-left:auto;" onsubmit="return confirm('Remove this post from the topic?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                        <input type="hidden" name="topic_id" value="<?= $topic_id ?>">
                        <input type="hidden" name="redirect" value="topic.php?slug=<?= urlencode($topic['slug']) ?>">
                        <button type="submit" class="report-link" style="background:none;border:none;cursor:pointer;">
                            Remove from topic
                        </button>
                    </form>
                <?php endif; ?>
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
                    <input type="hidden" name="redirect" value="topic.php?slug=<?= urlencode($topic['slug']) ?>">
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
                    <input type="hidden" name="redirect" value="topic.php?slug=<?= urlencode($topic['slug']) ?>">
                    <button type="submit" class="bookmark-btn <?= in_array($post['id'], $my_bookmarks) ? 'bookmarked' : '' ?>">
                        <i data-lucide="bookmark" class="bookmark-icon"></i>
                    </button>
                </form>
                <?php if ((int)$post['author_id'] === $user_id): ?>
                    <a href="edit_post.php?id=<?= $post['id'] ?>&from=<?= urlencode('topic.php?slug=' . $topic['slug']) ?>" class="edit-link">
                        <i data-lucide="pencil" style="width:14px;height:14px;"></i>
                        Edit
                    </a>
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
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>