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

// Record views for posts in the feed (only for posts by others)
if (!empty($feed)) {
    $view_post_ids = [];
    foreach ($feed as $p) {
        if ((int)$p['author_id'] !== $user_id) {
            $view_post_ids[] = $p['id'];
        }
    }

    if (!empty($view_post_ids)) {
        $placeholders = implode(',', array_fill(0, count($view_post_ids), '(?, ?)'));
        $params = [];
        foreach ($view_post_ids as $pid) {
            $params[] = $pid;
            $params[] = $user_id;
        }
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO post_views (post_id, user_id)
            VALUES $placeholders
        ");
        $stmt->execute($params);
    }
}

// ---- "Who to follow" suggestions ----
$suggestions = [];
$my_location = '';
$min_follows = 5;

// Check if user follows fewer than 5 people
$stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE follower_id = :me");
$stmt->execute([':me' => $user_id]);
$following_count = (int)$stmt->fetchColumn();

if ($following_count < $min_follows) {
    // Get user's location and languages
    $stmt = $pdo->prepare("SELECT location, languages FROM users WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $me_info = $stmt->fetch();
    $my_location = trim($me_info['location'] ?? '');
    $my_languages = trim($me_info['languages'] ?? '');

    // Reason 1: Friends of friends
    $stmt = $pdo->prepare("
        SELECT
            u.id, u.username, u.display_name, u.avatar,
            (SELECT u2.username FROM users u2
                INNER JOIN follows f2 ON f2.follower_id = u2.id
                WHERE f2.following_id = u.id
                  AND f2.follower_id IN (SELECT following_id FROM follows WHERE follower_id = :me)
                LIMIT 1) AS via_username,
            (SELECT u2.display_name FROM users u2
                INNER JOIN follows f2 ON f2.follower_id = u2.id
                WHERE f2.following_id = u.id
                  AND f2.follower_id IN (SELECT following_id FROM follows WHERE follower_id = :me)
                LIMIT 1) AS via_display,
            'friends_of_friends' AS source
        FROM users u
        WHERE u.id != :me
          AND u.id NOT IN (SELECT following_id FROM follows WHERE follower_id = :me)
          AND u.id IN (
              SELECT following_id FROM follows
              WHERE follower_id IN (SELECT following_id FROM follows WHERE follower_id = :me)
          )
        LIMIT 3
    ");
    $stmt->execute([':me' => $user_id]);
    $suggestions = array_merge($suggestions, $stmt->fetchAll());

    // Reason 2: Same location
    if ($my_location !== '' && count($suggestions) < 4) {
        $stmt = $pdo->prepare("
            SELECT
                id, username, display_name, avatar,
                'same_location' AS source
            FROM users
            WHERE id != :me
              AND id NOT IN (SELECT following_id FROM follows WHERE follower_id = :me)
              AND location = :location
            LIMIT 3
        ");
        $stmt->execute([':me' => $user_id, ':location' => $my_location]);
        $suggestions = array_merge($suggestions, $stmt->fetchAll());
    }

    // Reason 3: Shared language
    if ($my_languages !== '' && count($suggestions) < 4) {
        $my_lang_tokens = array_filter(array_map('trim', explode(',', $my_languages)));
        if (!empty($my_lang_tokens)) {
            $lang_conditions = [];
            $lang_params = [':me' => $user_id];
            foreach ($my_lang_tokens as $i => $lang) {
                $key = ":lang$i";
                $lang_conditions[] = "languages LIKE $key";
                $lang_params[$key] = '%' . $lang . '%';
            }
            $lang_sql = implode(' OR ', $lang_conditions);

            $stmt = $pdo->prepare("
                SELECT id, username, display_name, avatar, 'shared_language' AS source
                FROM users
                WHERE id != :me
                  AND id NOT IN (SELECT following_id FROM follows WHERE follower_id = :me)
                  AND ($lang_sql)
                LIMIT 3
            ");
            $stmt->execute($lang_params);
            $suggestions = array_merge($suggestions, $stmt->fetchAll());
        }
    }

    // Reason 4: New on Qarota (fallback)
    if (count($suggestions) < 4) {
        $stmt = $pdo->prepare("
            SELECT id, username, display_name, avatar, 'new_user' AS source
            FROM users
            WHERE id != :me
              AND id NOT IN (SELECT following_id FROM follows WHERE follower_id = :me)
            ORDER BY created_at DESC
            LIMIT 4
        ");
        $stmt->execute([':me' => $user_id]);
        $suggestions = array_merge($suggestions, $stmt->fetchAll());
    }

    // Deduplicate
    $seen = [];
    $unique = [];
    foreach ($suggestions as $s) {
        if (isset($seen[$s['id']])) continue;
        $seen[$s['id']] = true;
        $unique[] = $s;
        if (count($unique) >= 4) break;
    }
    $suggestions = $unique;
}

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
               comments.user_id,
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

<?php if (!empty($suggestions)): ?>
    <div class="card suggestions-card">
        <h2 style="margin-top:0;">Who to follow</h2>
        <p style="color: var(--muted); font-size: 0.9rem; margin-bottom: var(--space-4);">
            People you might want to connect with.
        </p>

        <?php foreach ($suggestions as $s): ?>
            <?php
            $s_name = $s['display_name'] ?: $s['username'];
            $reason = '';
            switch ($s['source']) {
                case 'friends_of_friends':
                    $via = $s['via_display'] ?? $s['via_username'] ?? 'someone you follow';
                    $reason = 'Because ' . $via . ' follows them';
                    break;
                case 'same_location':
                    $reason = 'In ' . htmlspecialchars($my_location);
                    break;
                case 'shared_language':
                    $reason = 'Speaks a language you do';
                    break;
                case 'new_user':
                    $reason = 'New on Qarota';
                    break;
            }
            ?>
            <div class="suggestion-row">
                <div class="suggestion-user">
                    <?php if ($s['avatar']): ?>
                        <img class="avatar avatar-small" src="<?= htmlspecialchars($s['avatar']) ?>" alt="">
                    <?php else: ?>
                        <div class="avatar avatar-small avatar-placeholder">
                            <?= strtoupper(substr($s_name, 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <div class="suggestion-info">
                        <a href="profile.php?u=<?= urlencode($s['username']) ?>" data-user-id="<?= $s['id'] ?>" class="suggestion-name">
                            <?= htmlspecialchars($s_name) ?>
                        </a>
                        <div class="suggestion-reason"><?= $reason ?></div>
                    </div>
                </div>
                <form method="POST" action="follow.php" class="follow-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="target_id" value="<?= $s['id'] ?>">
                    <input type="hidden" name="redirect" value="feed.php">
                    <button type="submit"
                            class="btn btn-small"
                            data-follow-text="Follow"
                            data-unfollow-text="Unfollow">
                        Follow
                    </button>
                </form>
            </div>
        <?php endforeach; ?>

        <p style="text-align:center; margin: var(--space-3) 0 0;">
            <a href="discover.php" style="font-size:0.9rem; color: var(--muted);">
                See more people →
            </a>
        </p>
    </div>
<?php endif; ?>

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
                    <a href="profile.php?u=<?= urlencode($post['username']) ?>" data-user-id="<?= $post['author_id'] ?>">
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
                                <a href="profile.php?u=<?= urlencode($c['username']) ?>" data-user-id="<?= $c['user_id'] ?>">
                                    <strong><?= htmlspecialchars($comment_name) ?></strong>
                                </a>
                            </div>
                            <?= htmlspecialchars($c['content']) ?>
                            <div class="meta" style="display:flex; justify-content:space-between; align-items:center;">
                                <span><?= htmlspecialchars($c['created_at']) ?></span>
                                <?php if ((int)$c['user_id'] !== $user_id): ?>
                                    <a href="report.php?comment_id=<?= $c['id'] ?>" class="report-link" style="font-size:0.75rem;">
                                        Report
                                    </a>
                                <?php endif; ?>
                            </div>
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