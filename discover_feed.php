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

// Get current user's location and languages
$stmt = $pdo->prepare("SELECT location, languages FROM users WHERE id = :id");
$stmt->execute([':id' => $user_id]);
$me = $stmt->fetch();
$my_location = trim($me['location'] ?? '');
$my_languages = trim($me['languages'] ?? '');

// Collect posts from multiple sources
$suggestions = [];

// ---- Reason 1: Friends of friends ----
// Posts from users followed by people you follow, excluding people you already follow
$stmt = $pdo->prepare("
    SELECT DISTINCT
        posts.id, posts.content, posts.media_path, posts.created_at,
        users.username, users.display_name, users.avatar, users.id AS author_id,
        (SELECT COUNT(*) FROM likes WHERE post_id = posts.id) AS like_count,
        (SELECT COUNT(*) FROM likes WHERE post_id = posts.id AND user_id = :me_like) AS liked_by_me,
        (SELECT COUNT(*) FROM comments WHERE post_id = posts.id) AS comment_count,
        (SELECT u2.display_name FROM users u2 INNER JOIN follows f2 ON f2.follower_id = u2.id WHERE f2.following_id = posts.user_id AND f2.follower_id IN (SELECT following_id FROM follows WHERE follower_id = :me) LIMIT 1) AS reason_via,
        (SELECT u2.username FROM users u2 INNER JOIN follows f2 ON f2.follower_id = u2.id WHERE f2.following_id = posts.user_id AND f2.follower_id IN (SELECT following_id FROM follows WHERE follower_id = :me) LIMIT 1) AS reason_via_username,
        'friends_of_friends' AS source
    FROM posts
    INNER JOIN users ON posts.user_id = users.id
    WHERE posts.user_id != :me
      AND posts.user_id NOT IN (SELECT following_id FROM follows WHERE follower_id = :me)
      AND posts.user_id IN (
          SELECT following_id FROM follows
          WHERE follower_id IN (SELECT following_id FROM follows WHERE follower_id = :me)
      )
    ORDER BY posts.created_at DESC
    LIMIT 10
");
$stmt->execute([':me' => $user_id, ':me_like' => $user_id]);
$suggestions = array_merge($suggestions, $stmt->fetchAll());

// ---- Reason 2: Same location ----
if ($my_location !== '') {
    $stmt = $pdo->prepare("
        SELECT
            posts.id, posts.content, posts.media_path, posts.created_at,
            users.username, users.display_name, users.avatar, users.id AS author_id,
            (SELECT COUNT(*) FROM likes WHERE post_id = posts.id) AS like_count,
            (SELECT COUNT(*) FROM likes WHERE post_id = posts.id AND user_id = :me_like) AS liked_by_me,
            (SELECT COUNT(*) FROM comments WHERE post_id = posts.id) AS comment_count,
            'same_location' AS source
        FROM posts
        INNER JOIN users ON posts.user_id = users.id
        WHERE posts.user_id != :me
          AND posts.user_id NOT IN (SELECT following_id FROM follows WHERE follower_id = :me)
          AND users.location = :location
        ORDER BY posts.created_at DESC
        LIMIT 8
    ");
    $stmt->execute([':me' => $user_id, ':me_like' => $user_id, ':location' => $my_location]);
    $suggestions = array_merge($suggestions, $stmt->fetchAll());
}

// ---- Reason 3: Shared language ----
if ($my_languages !== '') {
    // Build a list of my language tokens
    $my_lang_tokens = array_filter(array_map('trim', explode(',', $my_languages)));

    if (!empty($my_lang_tokens)) {
        // Build a LIKE clause: users.languages LIKE '%Twi%' OR LIKE '%English%' ...
        $lang_conditions = [];
        $lang_params = [':me' => $user_id, ':me_like' => $user_id];
        foreach ($my_lang_tokens as $i => $lang) {
            $key = ":lang$i";
            $lang_conditions[] = "users.languages LIKE $key";
            $lang_params[$key] = '%' . $lang . '%';
        }
        $lang_sql = implode(' OR ', $lang_conditions);

        $stmt = $pdo->prepare("
            SELECT
                posts.id, posts.content, posts.media_path, posts.created_at,
                users.username, users.display_name, users.avatar, users.id AS author_id,
                (SELECT COUNT(*) FROM likes WHERE post_id = posts.id) AS like_count,
                (SELECT COUNT(*) FROM likes WHERE post_id = posts.id AND user_id = :me_like) AS liked_by_me,
                (SELECT COUNT(*) FROM comments WHERE post_id = posts.id) AS comment_count,
                'shared_language' AS source
            FROM posts
            INNER JOIN users ON posts.user_id = users.id
            WHERE posts.user_id != :me
              AND posts.user_id NOT IN (SELECT following_id FROM follows WHERE follower_id = :me)
              AND ($lang_sql)
            ORDER BY posts.created_at DESC
            LIMIT 8
        ");
        $stmt->execute($lang_params);
        $suggestions = array_merge($suggestions, $stmt->fetchAll());
    }
}

// ---- Reason 4: Trending today ----
$stmt = $pdo->prepare("
    SELECT
        posts.id, posts.content, posts.media_path, posts.created_at,
        users.username, users.display_name, users.avatar, users.id AS author_id,
        (SELECT COUNT(*) FROM likes WHERE post_id = posts.id) AS like_count,
        (SELECT COUNT(*) FROM likes WHERE post_id = posts.id AND user_id = :me_like) AS liked_by_me,
        (SELECT COUNT(*) FROM comments WHERE post_id = posts.id) AS comment_count,
        'trending' AS source
    FROM posts
    INNER JOIN users ON posts.user_id = users.id
    WHERE posts.user_id != :me
      AND posts.user_id NOT IN (SELECT following_id FROM follows WHERE follower_id = :me)
      AND posts.created_at > NOW() - INTERVAL 48 HOUR
      AND (SELECT COUNT(*) FROM likes WHERE post_id = posts.id) >= 2
    ORDER BY like_count DESC, posts.created_at DESC
    LIMIT 6
");
$stmt->execute([':me' => $user_id, ':me_like' => $user_id]);
$suggestions = array_merge($suggestions, $stmt->fetchAll());

// ---- Deduplicate and sort ----
$seen = [];
$unique = [];
foreach ($suggestions as $s) {
    if (isset($seen[$s['id']])) continue;
    $seen[$s['id']] = true;
    $unique[] = $s;
}
usort($unique, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});
// Limit total to 30
$discover = array_slice($unique, 0, 30);

// ---- Fetch comments for these posts ----
$post_ids = array_column($discover, 'id');
$comments_by_post = [];
if (!empty($post_ids)) {
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

// ---- Check which authors we follow (for follow buttons) ----
$author_ids = array_unique(array_column($discover, 'author_id'));
$following_map = [];
if (!empty($author_ids)) {
    $placeholders = implode(',', array_fill(0, count($author_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT following_id FROM follows
        WHERE follower_id = ? AND following_id IN ($placeholders)
    ");
    $stmt->execute(array_merge([$user_id], $author_ids));
    foreach ($stmt->fetchAll() as $row) {
        $following_map[$row['following_id']] = true;
    }
}

$page_title = 'Discover';
require 'includes/header.php';
?>

<h1>Discover</h1>
<p style="color: var(--muted); margin-bottom: var(--space-5);">
    Posts from people you don't follow yet. Every suggestion says why.
</p>

<?php if (empty($discover)): ?>
    <div class="empty">
        <strong>Nothing to discover yet</strong>
        Follow more people or add your location and languages to your profile to see suggestions here.
    </div>
<?php else: ?>
    <?php foreach ($discover as $post): ?>
        <?php
        $author_name = $post['display_name'] ?: $post['username'];
        $reason_text = '';
        switch ($post['source']) {
            case 'friends_of_friends':
                $via = $post['reason_via'] ?? $post['reason_via_username'] ?? 'someone you follow';
                $reason_text = 'Because ' . $via . ' follows them';
                break;
            case 'same_location':
                $reason_text = 'Because you\'re in ' . htmlspecialchars($my_location) . ' too';
                break;
            case 'shared_language':
                $reason_text = 'Because you share a language';
                break;
            case 'trending':
                $reason_text = 'Trending on Qarota today';
                break;
        }
        ?>
        <div class="card">
            <?php if ($reason_text): ?>
                <div class="discover-reason">
                    <span class="discover-reason-icon">📌</span>
                    <span><?= htmlspecialchars($reason_text) ?></span>
                </div>
            <?php endif; ?>

            <div class="post-header">
                <?php if ($post['avatar']): ?>
                    <img class="avatar avatar-small"
                         src="<?= htmlspecialchars($post['avatar']) ?>"
                         alt="">
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
                <?php if (!isset($following_map[$post['author_id']])): ?>
                    <form method="POST" action="follow.php" class="follow-form" style="margin-left: auto;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="target_id" value="<?= $post['author_id'] ?>">
                        <input type="hidden" name="redirect" value="discover_feed.php">
                        <button type="submit" class="btn-secondary btn-small"
                                data-follow-text="Follow"
                                data-unfollow-text="Unfollow">
                            Follow
                        </button>
                    </form>
                <?php endif; ?>
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
                    <input type="hidden" name="redirect" value="discover_feed.php">
                    <button type="submit" class="like-btn <?= $post['liked_by_me'] ? 'liked' : '' ?>">
                        <span class="like-heart"><?= $post['liked_by_me'] ? '♥' : '♡' ?></span>
                        <span class="like-count"><?= (int)$post['like_count'] ?></span>
                    </button>
                </form>
                <span class="comment-count">💬 <?= (int)$post['comment_count'] ?></span>
                <a href="report.php?post_id=<?= $post['id'] ?>" class="report-link">Report</a>
            </div>

            <?php if (!empty($comments_by_post[$post['id']])): ?>
                <div class="comments">
                    <?php foreach ($comments_by_post[$post['id']] as $c): ?>
                        <?php $comment_name = $c['display_name'] ?: $c['username']; ?>
                        <div class="comment">
                            <div class="comment-header">
                                <?php if (!empty($c['avatar'])): ?>
                                    <img class="avatar avatar-tiny"
                                         src="<?= htmlspecialchars($c['avatar']) ?>"
                                         alt="">
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
                <input type="hidden" name="redirect" value="discover_feed.php">
                <input type="text" name="content" placeholder="Write a comment..." maxlength="500" required>
                <button type="submit">Send</button>
            </form>
        </div>
    <?php endforeach; ?>

    <div class="end-of-feed">
        <strong>That's all for today</strong>
        Come back tomorrow for fresh suggestions.
    </div>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>