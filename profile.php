<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Backward compatibility: redirect old ?id= URLs
if (isset($_GET['id']) && !isset($_GET['u'])) {
    $old_id = (int)$_GET['id'];
    if ($old_id > 0) {
        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = :id");
        $stmt->execute([':id' => $old_id]);
        $old_username = $stmt->fetchColumn();
        if ($old_username) {
            header('Location: profile.php?u=' . urlencode($old_username), true, 301);
            exit;
        }
    }
    header('Location: feed.php');
    exit;
}

$profile_username = $_GET['u'] ?? $_SESSION['username'];

$stmt = $pdo->prepare("
    SELECT
        id, username, avatar, bio,
        display_name, location, occupation, education, languages, interests,
        (SELECT COUNT(*) FROM follows WHERE following_id = users.id) AS follower_count,
        (SELECT COUNT(*) FROM follows WHERE follower_id = users.id) AS following_count,
        (SELECT COUNT(*) FROM posts WHERE user_id = users.id) AS post_count
    FROM users
    WHERE username = :username
");
$stmt->execute([':username' => $profile_username]);
$profile_user = $stmt->fetch();

if (!$profile_user) {
    header('Location: feed.php');
    exit;
}

$profile_id = (int)$profile_user['id'];
$is_own_profile = ($profile_id === (int)$_SESSION['user_id']);

$display_name = $profile_user['display_name'] ?: $profile_user['username'];

$is_following = false;
if (!$is_own_profile) {
    $stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = :me AND following_id = :them");
    $stmt->execute([':me' => $_SESSION['user_id'], ':them' => $profile_id]);
    $is_following = (bool)$stmt->fetch();
}

$message = '';
$avatar_message = $_SESSION['avatar_message'] ?? '';
unset($_SESSION['avatar_message']);

// ---- Handle new post (with optional topic tag) ----
if ($is_own_profile && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty(trim($_POST['content']))) {
    csrf_verify();
    $content = trim($_POST['content']);
    $media_path = null;

    if (!empty($_FILES['image']['name'])) {
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $max_size = 5 * 1024 * 1024;

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowed)) {
            $message = __('upload_only_images');
        } elseif ($_FILES['image']['size'] > $max_size) {
            $message = __('upload_too_big');
        } elseif ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $message = __('upload_failed');
        } else {
            $ext = match($mime) {
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp',
            };
            $filename = bin2hex(random_bytes(16)) . '.' . $ext;
            $destination = __DIR__ . '/uploads/' . $filename;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
                $media_path = 'uploads/' . $filename;
            } else {
                $message = __('upload_save_error');
            }
        }
    }

    if (empty($message)) {
        $stmt = $pdo->prepare("INSERT INTO posts (user_id, content, media_path) VALUES (:user_id, :content, :media)");
        $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':content' => $content,
            ':media'   => $media_path,
        ]);
        $new_post_id = (int)$pdo->lastInsertId();

        // Attach topic if selected and user is a member
        $topic_id = (int)($_POST['topic_id'] ?? 0);
        if ($topic_id > 0) {
            $stmt = $pdo->prepare("SELECT 1 FROM topic_members WHERE topic_id = :t AND user_id = :u");
            $stmt->execute([':t' => $topic_id, ':u' => $_SESSION['user_id']]);
            if ($stmt->fetch()) {
                $stmt = $pdo->prepare("INSERT INTO post_topics (post_id, topic_id) VALUES (:p, :t)");
                $stmt->execute([':p' => $new_post_id, ':t' => $topic_id]);

                // Notify all other members of this topic
                $stmt = $pdo->prepare("
    INSERT INTO notifications (user_id, actor_id, type, post_id, topic_id)
    SELECT tm.user_id, :actor, 'topic_posted', :post_id, :topic_id
    FROM topic_members tm
    INNER JOIN users u ON u.id = tm.user_id
    WHERE tm.topic_id = :topic_id
      AND tm.user_id != :actor
      AND u.notify_topic_posts = 1
      AND u.notify_paused = 0
");
$stmt->execute([
    ':actor'    => $_SESSION['user_id'],
    ':post_id'  => $new_post_id,
    ':topic_id' => $topic_id,
]);
            }
        }

        $message = __('post_published');
    }
}

// ---- Fetch topics the user is a member of (for the composer dropdown) ----
$my_topics = [];
if ($is_own_profile) {
    $stmt = $pdo->prepare("
        SELECT t.id, t.name, t.slug
        FROM topics t
        INNER JOIN topic_members tm ON tm.topic_id = t.id
        WHERE tm.user_id = :me
        ORDER BY t.name ASC
    ");
    $stmt->execute([':me' => $_SESSION['user_id']]);
    $my_topics = $stmt->fetchAll();
}

// ---- Fetch the profile user's posts (WITH edited_at) ----
$stmt = $pdo->prepare("
    SELECT
        posts.id, posts.content, posts.media_path, posts.created_at, posts.edited_at,
        users.avatar,
        (SELECT COUNT(*) FROM likes WHERE post_id = posts.id) AS like_count,
        (SELECT COUNT(*) FROM likes WHERE post_id = posts.id AND user_id = :me_like) AS liked_by_me,
        (SELECT COUNT(*) FROM comments WHERE post_id = posts.id) AS comment_count
    FROM posts
    INNER JOIN users ON posts.user_id = users.id
    WHERE posts.user_id = :user_id
    ORDER BY posts.created_at DESC
");
$stmt->execute([':user_id' => $profile_id, ':me_like' => $_SESSION['user_id']]);
$posts = $stmt->fetchAll();

// ---- Fetch user's bookmarked post IDs ----
$stmt = $pdo->prepare("SELECT post_id FROM bookmarks WHERE user_id = :me");
$stmt->execute([':me' => $_SESSION['user_id']]);
$my_bookmarks = array_column($stmt->fetchAll(), 'post_id');

// ---- Topics attached to each post ----
$topics_by_post = [];
if (!empty($posts)) {
    $post_ids = array_column($posts, 'id');
    $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT post_topics.post_id, topics.name, topics.slug
        FROM post_topics
        INNER JOIN topics ON topics.id = post_topics.topic_id
        WHERE post_topics.post_id IN ($placeholders)
    ");
    $stmt->execute($post_ids);
    foreach ($stmt->fetchAll() as $t) {
        $topics_by_post[$t['post_id']][] = $t;
    }
}

// ---- Comments for these posts ----
$comments_by_post = [];
if (!empty($posts)) {
    $post_ids = array_column($posts, 'id');
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

$page_title = $display_name;
require 'includes/header.php';
?>

<div class="profile-header">
    <?php if ($profile_user['avatar']): ?>
        <img class="avatar avatar-large"
             src="<?= htmlspecialchars($profile_user['avatar']) ?>"
             alt="Profile picture">
    <?php else: ?>
        <div class="avatar avatar-large avatar-placeholder">
            <?= strtoupper(substr($display_name, 0, 1)) ?>
        </div>
    <?php endif; ?>

    <div class="profile-info">
        <h1 style="margin-bottom:2px;">
            <?= $is_own_profile ? __('profile_hello') . ' ' : '' ?>
            <?= htmlspecialchars($display_name) ?>
        </h1>

        <?php if ($profile_user['display_name']): ?>
            <div class="profile-username">@<?= htmlspecialchars($profile_user['username']) ?></div>
        <?php endif; ?>

        <div class="profile-stats">
            <strong><?= (int)$profile_user['post_count'] ?></strong> <?= __('profile_posts_label') ?>
            <span class="stat-dot">·</span>
            <strong><?= (int)$profile_user['follower_count'] ?></strong>
            <?= $profile_user['follower_count'] == 1 ? 'follower' : __('profile_followers_label') ?>
            <span class="stat-dot">·</span>
            <strong><?= (int)$profile_user['following_count'] ?></strong> <?= __('profile_following_label') ?>
        </div>

        <?php if (!empty($profile_user['bio'])): ?>
            <p class="profile-bio"><?= nl2br(htmlspecialchars($profile_user['bio'])) ?></p>
        <?php endif; ?>

        <?php
        $has_details = $profile_user['location'] || $profile_user['occupation']
            || $profile_user['education'] || $profile_user['languages']
            || $profile_user['interests'];
        ?>
        <?php if ($has_details): ?>
            <div class="profile-details">
                <?php if ($profile_user['location']): ?>
                    <div class="profile-detail">
                        <i data-lucide="map-pin" class="profile-detail-icon"></i>
                        <span><?= htmlspecialchars($profile_user['location']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($profile_user['occupation']): ?>
                    <div class="profile-detail">
                        <i data-lucide="briefcase" class="profile-detail-icon"></i>
                        <span><?= htmlspecialchars($profile_user['occupation']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($profile_user['education']): ?>
                    <div class="profile-detail">
                        <i data-lucide="graduation-cap" class="profile-detail-icon"></i>
                        <span><?= htmlspecialchars($profile_user['education']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($profile_user['languages']): ?>
                    <div class="profile-detail">
                        <i data-lucide="languages" class="profile-detail-icon"></i>
                        <span><?= htmlspecialchars($profile_user['languages']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($profile_user['interests']): ?>
                    <div class="profile-detail">
                        <i data-lucide="star" class="profile-detail-icon"></i>
                        <span><?= htmlspecialchars($profile_user['interests']) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($is_own_profile): ?>
            <div class="profile-actions">
                <a href="edit_profile.php" class="btn-secondary"><?= __('bio_edit_link') ?></a>
                <form method="POST" action="avatar.php" enctype="multipart/form-data" style="display:inline;">
                    <?= csrf_field() ?>
                    <label for="avatar" class="btn-secondary"><?= __('profile_change_picture') ?></label>
                    <input type="file" id="avatar" name="avatar"
                           accept="image/jpeg,image/png,image/gif,image/webp"
                           onchange="this.form.submit()" style="display:none;">
                </form>
                <a href="saved.php" class="btn-secondary">Saved posts</a>
                <a href="export.php" class="btn-secondary"><?= __('profile_export') ?></a>
                <a href="privacy.php" class="btn-secondary"><?= __('profile_privacy') ?></a>
                <a href="delete_account.php" class="btn-danger"><?= __('profile_delete') ?></a>
            </div>
        <?php else: ?>
            <form method="POST" action="follow.php" class="follow-form" style="margin-top:8px;">
                <?= csrf_field() ?>
                <input type="hidden" name="target_id" value="<?= $profile_id ?>">
                <input type="hidden" name="redirect" value="profile.php?u=<?= urlencode($profile_user['username']) ?>">
                <button type="submit"
                        class="<?= $is_following ? 'btn-secondary' : '' ?>"
                        data-follow-text="<?= __('profile_follow') ?>"
                        data-unfollow-text="<?= __('profile_unfollow') ?>">
                    <?= $is_following ? __('profile_unfollow') : __('profile_follow') ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
    <div class="message"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if ($avatar_message): ?>
    <div class="message"><?= htmlspecialchars($avatar_message) ?></div>
<?php endif; ?>

<?php if ($is_own_profile): ?>
    <div class="card">
        <h2 style="margin-top:0;"><?= __('post_share') ?></h2>
        <form method="POST" enctype="multipart/form-data" class="stack">
            <?= csrf_field() ?>
            <textarea name="content" maxlength="500" placeholder="<?= __('post_placeholder') ?>" required></textarea>

            <?php if (!empty($my_topics)): ?>
                <div class="form-field">
                    <label for="topic_id">Tag with a topic <span class="optional">(optional)</span></label>
                    <select id="topic_id" name="topic_id">
                        <option value="">— No topic —</option>
                        <?php foreach ($my_topics as $t): ?>
                            <option value="<?= $t['id'] ?>">#<?= htmlspecialchars($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
            <button type="submit"><?= __('post_button') ?></button>
        </form>
    </div>
<?php endif; ?>

<?php if (empty($posts)): ?>
    <div class="empty"><?= __('profile_no_posts') ?></div>
<?php else: ?>
    <?php foreach ($posts as $post): ?>
        <div class="card">
            <div class="post-header">
                <?php if ($post['avatar']): ?>
                    <img class="avatar avatar-small" src="<?= htmlspecialchars($post['avatar']) ?>" alt="">
                <?php else: ?>
                    <div class="avatar avatar-small avatar-placeholder">
                        <?= strtoupper(substr($display_name, 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <div class="author">
                    <a href="profile.php?u=<?= urlencode($profile_user['username']) ?>" data-user-id="<?= $profile_id ?>">
    <?= htmlspecialchars($display_name) ?>
</a>
                </div>
            </div>

            <div class="content"><?= htmlspecialchars($post['content']) ?></div>

            <?php if (!empty($topics_by_post[$post['id']])): ?>
                <div style="margin: var(--space-2) 0;">
                    <?php foreach ($topics_by_post[$post['id']] as $t): ?>
                        <a href="topic.php?slug=<?= urlencode($t['slug']) ?>" class="topic-tag">
                            #<?= htmlspecialchars($t['name']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

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
                    <input type="hidden" name="redirect" value="profile.php?u=<?= urlencode($profile_user['username']) ?>">
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
                    <input type="hidden" name="redirect" value="profile.php?u=<?= urlencode($profile_user['username']) ?>">
                    <button type="submit" class="bookmark-btn <?= in_array($post['id'], $my_bookmarks) ? 'bookmarked' : '' ?>">
                        <i data-lucide="bookmark" class="bookmark-icon"></i>
                    </button>
                </form>
                <?php if ($is_own_profile): ?>
                    <a href="edit_post.php?id=<?= $post['id'] ?>&from=<?= urlencode('profile.php?u=' . $profile_user['username']) ?>" class="edit-link">
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
                <input type="hidden" name="redirect" value="profile.php?u=<?= urlencode($profile_user['username']) ?>">
                <input type="text" name="content" placeholder="<?= __('post_comment_placeholder') ?>" maxlength="500" required>
                <button type="submit"><?= __('post_send') ?></button>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>