<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require_once __DIR__ . '/includes/posts.php';
require_once __DIR__ . '/includes/voice_upload.php';
require_once __DIR__ . '/includes/video_upload.php';
require_once __DIR__ . '/includes/badge.php';
require_once __DIR__ . '/includes/language.php';
require 'includes/messages.php';
require 'includes/blocks.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

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
$viewer_id = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $viewer_id]);
$viewer_is_admin = (int)$stmt->fetchColumn() === 1;

$profile_count_visibility_sql = post_visibility_filter('counted_posts', 'profile_count');

$stmt = $pdo->prepare("
    SELECT
        id, username, avatar, bio,
        display_name, location, occupation, education, languages, interests,
        is_banned, ban_reason, is_admin,
        verification_type, verified_at,
        (SELECT COUNT(*) FROM follows WHERE following_id = users.id) AS follower_count,
        (SELECT COUNT(*) FROM follows WHERE follower_id = users.id) AS following_count,
        (SELECT COUNT(*) FROM posts counted_posts
         WHERE counted_posts.user_id = users.id
           AND $profile_count_visibility_sql) AS post_count
    FROM users
    WHERE username = :username
");
$stmt->execute(array_merge(
    [':username' => $profile_username],
    post_visibility_params($viewer_id, 'profile_count')
));
$profile_user = $stmt->fetch();

if (!$profile_user) {
    header('Location: feed.php');
    exit;
}

$profile_id        = (int)$profile_user['id'];
$is_own_profile    = ($profile_id === $viewer_id);
$display_name      = $profile_user['display_name'] ?: $profile_user['username'];
$profile_is_admin  = (int)($profile_user['is_admin'] ?? 0) === 1;
$profile_is_banned = (int)($profile_user['is_banned'] ?? 0) === 1;
$profile_is_verified = ($profile_user['verification_type'] ?? 'none') !== 'none';

$is_following = false;
if (!$is_own_profile) {
    $stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = :me AND following_id = :them");
    $stmt->execute([':me' => $_SESSION['user_id'], ':them' => $profile_id]);
    $is_following = (bool)$stmt->fetch();
}

$i_blocked_them  = false;
$they_blocked_me = false;
$blocked_either  = false;
if (!$is_own_profile) {
    $i_blocked_them  = is_blocked($pdo, (int)$_SESSION['user_id'], $profile_id);
    $they_blocked_me = is_blocked($pdo, $profile_id, (int)$_SESSION['user_id']);
    $blocked_either  = $i_blocked_them || $they_blocked_me;
}

$message = '';
$avatar_message = $_SESSION['avatar_message'] ?? '';
unset($_SESSION['avatar_message']);

$flash_error   = $_SESSION['flash_error']   ?? '';
$flash_success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

if ($is_own_profile && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    rate_limit_enforce($pdo, client_ip(), 'profile_post', 30, 3600);

    $content    = trim($_POST['content'] ?? '');
    $visibility = $_POST['visibility'] ?? 'public';
    if (!in_array($visibility, ['public', 'followers', 'private'], true)) {
        $visibility = 'public';
    }

    $has_image = !empty($_FILES['image']['name'] ?? '') && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    $has_voice = !empty($_FILES['voice']['name'] ?? '') && ($_FILES['voice']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    $has_video = !empty($_FILES['video']['name'] ?? '') && ($_FILES['video']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

    $media_count = (int)$has_image + (int)$has_voice + (int)$has_video;

    if ($content === '' && $media_count === 0) {
        $message = 'Please write something or attach media.';
    } elseif ($media_count > 1) {
        $message = 'Please attach only one type of media (photo, video, or voice note).';
    } else {
        $media_path     = null;
        $media_type     = 'text';
        $media_duration = null;
        $media_mime     = null;
        $video_thumb    = null;
        $video_width    = null;
        $video_height   = null;

        // ---- Image ----
        if ($has_image) {
            $allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $max_size = 5 * 1024 * 1024;

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $_FILES['image']['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime, $allowed)) {
                $message = __('upload_only_images');
            } elseif ($_FILES['image']['size'] > $max_size) {
                $message = __('upload_too_big');
            } else {
                $ext = match($mime) {
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/gif'  => 'gif',
                    'image/webp' => 'webp',
                };
                $filename        = bin2hex(random_bytes(16)) . '.' . $ext;
                $media_directory = $visibility !== 'public'
                    ? 'uploads/private_posts/'
                    : 'uploads/';
                $destination     = __DIR__ . '/' . $media_directory . $filename;

                if (move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
                    $media_path = $media_directory . $filename;
                    $media_type = 'image';
                    $media_mime = $mime;
                } else {
                    $message = __('upload_save_error');
                }
            }
        }

        // ---- Voice ----
        if (empty($message) && $has_voice) {
            $duration = (int)($_POST['voice_duration'] ?? 0);
            try {
                $v = handle_voice_upload($_FILES['voice'], $duration);
                $media_path     = $v['path'];
                $media_type     = 'voice';
                $media_duration = $v['duration'];
                $media_mime     = $v['mime'];
            } catch (Throwable $e) {
                $message = $e->getMessage();
            }
        }

        // ---- Video ----
        if (empty($message) && $has_video) {
            $duration = (int)($_POST['video_duration'] ?? 0);
            try {
                $v = handle_video_upload($_FILES['video'], $duration);

                if ($visibility !== 'public') {
                    $v = move_post_video_to_private($v);
                }

                $media_path     = $v['path'];
                $media_type     = 'video';
                $media_duration = $v['duration'];
                $media_mime     = $v['mime'];
                $video_thumb    = $v['thumb'];
                $video_width    = $v['width']  ?? null;
                $video_height   = $v['height'] ?? null;
            } catch (Throwable $e) {
                $message = $e->getMessage();
            }
        }

        if (empty($message)) {
            $post_lang = detect_post_language($content, $profile_user['languages'] ?? null);

            $stmt = $pdo->prepare(
                "INSERT INTO posts
                    (user_id, content, lang, media_path, media_type, media_duration, media_mime,
                     video_duration, video_thumb, video_width, video_height, visibility)
                 VALUES
                    (:user_id, :content, :lang, :media, :mtype, :mdur, :mmime,
                     :vdur, :vthumb, :vwidth, :vheight, :visibility)"
            );
            $stmt->execute([
                ':user_id'    => $_SESSION['user_id'],
                ':content'    => $content,
                ':lang'       => $post_lang,
                ':media'      => $media_path,
                ':mtype'      => $media_type,
                ':mdur'       => $media_duration,
                ':mmime'      => $media_mime,
                ':vdur'       => $media_type === 'video' ? $media_duration : null,
                ':vthumb'     => $video_thumb,
                ':vwidth'     => $video_width,
                ':vheight'    => $video_height,
                ':visibility' => $visibility,
            ]);
            $new_post_id = (int)$pdo->lastInsertId();

            $topic_id = (int)($_POST['topic_id'] ?? 0);
            if ($topic_id > 0) {
                $stmt = $pdo->prepare("SELECT 1 FROM topic_members WHERE topic_id = :t AND user_id = :u");
                $stmt->execute([':t' => $topic_id, ':u' => $_SESSION['user_id']]);
                if ($stmt->fetch()) {
                    $stmt = $pdo->prepare("INSERT INTO post_topics (post_id, topic_id) VALUES (:p, :t)");
                    $stmt->execute([':p' => $new_post_id, ':t' => $topic_id]);

                    $stmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, actor_id, type, post_id, topic_id)
                        SELECT tm.user_id, :actor_insert, 'topic_posted', :post_id, :topic_id
                        FROM topic_members tm
                        INNER JOIN users u ON u.id = tm.user_id
                        WHERE tm.topic_id = :topic_id
                          AND tm.user_id != :actor_exclude
                          AND u.notify_topic_posts = 1
                          AND u.notify_paused = 0
                          AND (
                              :topic_visibility_public = 'public'
                              OR (
                                  :topic_visibility_followers = 'followers'
                                  AND EXISTS (
                                      SELECT 1 FROM follows topic_follow
                                      WHERE topic_follow.follower_id = tm.user_id
                                        AND topic_follow.following_id = :topic_author
                                  )
                              )
                          )
                          AND NOT EXISTS (
                              SELECT 1 FROM user_blocks topic_block
                              WHERE (topic_block.blocker_id = tm.user_id
                                     AND topic_block.blocked_id = :topic_author_blocked)
                                 OR (topic_block.blocker_id = :topic_blocker
                                     AND topic_block.blocked_id = tm.user_id)
                          )
                    ");
                    $stmt->execute([
                        ':actor_insert'                => $_SESSION['user_id'],
                        ':actor_exclude'               => $_SESSION['user_id'],
                        ':topic_author'                => $_SESSION['user_id'],
                        ':topic_author_blocked'        => $_SESSION['user_id'],
                        ':topic_blocker'               => $_SESSION['user_id'],
                        ':topic_visibility_public'     => $visibility,
                        ':topic_visibility_followers'  => $visibility,
                        ':post_id'                     => $new_post_id,
                        ':topic_id'                    => $topic_id,
                    ]);
                }
            }

            $_SESSION['flash_success'] = __('post_published');
            header('Location: profile.php' . ($is_own_profile ? '' : '?u=' . urlencode($profile_user['username'])));
            exit;
        } else {
            $_SESSION['flash_error'] = $message;
            header('Location: profile.php' . ($is_own_profile ? '' : '?u=' . urlencode($profile_user['username'])));
            exit;
        }
    }
}

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

$profile_visibility_sql = post_visibility_filter('posts', 'profile_posts');
$stmt = $pdo->prepare("
    SELECT
        posts.id, posts.content, posts.media_path, posts.created_at, posts.edited_at,
        posts.visibility,
        posts.media_type, posts.media_duration, posts.media_mime,
        posts.video_duration, posts.video_thumb, posts.video_width, posts.video_height,
        users.avatar, users.verification_type,
        (SELECT COUNT(*) FROM likes WHERE post_id = posts.id) AS like_count,
        (SELECT COUNT(*) FROM likes WHERE post_id = posts.id AND user_id = :me_like) AS liked_by_me,
        (SELECT COUNT(*) FROM comments WHERE post_id = posts.id) AS comment_count,
        (SELECT COUNT(*) FROM post_views WHERE post_id = posts.id) AS view_count
    FROM posts
    INNER JOIN users ON posts.user_id = users.id
    WHERE posts.user_id = :user_id
      AND $profile_visibility_sql
    ORDER BY posts.created_at DESC
");
$stmt->execute(array_merge(
    [':user_id' => $profile_id, ':me_like' => $viewer_id],
    post_visibility_params($viewer_id, 'profile_posts')
));
$posts = $stmt->fetchAll();

if (!$is_own_profile && !empty($posts)) {
    $view_post_ids = array_column($posts, 'id');
    $placeholders = implode(',', array_fill(0, count($view_post_ids), '(?, ?)'));
    $params = [];
    foreach ($view_post_ids as $pid) {
        $params[] = $pid;
        $params[] = $_SESSION['user_id'];
    }
    $stmt = $pdo->prepare("INSERT IGNORE INTO post_views (post_id, user_id) VALUES $placeholders");
    $stmt->execute($params);
}

$stmt = $pdo->prepare("SELECT post_id FROM bookmarks WHERE user_id = :me");
$stmt->execute([':me' => $_SESSION['user_id']]);
$my_bookmarks = array_column($stmt->fetchAll(), 'post_id');

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

$comments_by_post = [];
if (!empty($posts)) {
    $post_ids = array_column($posts, 'id');
    $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT comments.id, comments.post_id, comments.user_id, comments.content, comments.created_at,
               users.username, users.display_name, users.avatar, users.verification_type
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

<?php if ($viewer_is_admin && $profile_is_banned): ?>
    <div class="message" style="border-left-color: var(--danger); background: #FDECEA;">
        <strong>This user is banned.</strong>
        <?php if (!empty($profile_user['ban_reason'])): ?>
            <div style="margin-top: 4px;">Reason: <?= htmlspecialchars($profile_user['ban_reason']) ?></div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="profile-header">
    <?php if ($profile_user['avatar']): ?>
        <img class="avatar avatar-large" src="<?= htmlspecialchars($profile_user['avatar']) ?>" alt="Profile picture">
    <?php else: ?>
        <div class="avatar avatar-large avatar-placeholder">
            <?= strtoupper(substr($display_name, 0, 1)) ?>
        </div>
    <?php endif; ?>

    <div class="profile-info">
        <h1 style="margin-bottom:2px;">
            <?= $is_own_profile ? __('profile_hello') . ' ' : '' ?>
            <?= htmlspecialchars($display_name) ?><?= verification_badge($profile_user['verification_type'] ?? 'none', 20) ?>
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
            <div class="profile-actions" style="margin-top: 8px;">
                <?php if (!$blocked_either): ?>
                    <form method="POST" action="follow.php" class="follow-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="target_id" value="<?= $profile_id ?>">
                        <input type="hidden" name="redirect"
                               value="profile.php?u=<?= urlencode($profile_user['username']) ?>">
                        <button type="submit"
                                class="<?= $is_following ? 'btn-secondary' : '' ?>"
                                data-follow-text="<?= __('profile_follow') ?>"
                                data-unfollow-text="<?= __('profile_unfollow') ?>">
                            <?= $is_following ? __('profile_unfollow') : __('profile_follow') ?>
                        </button>
                    </form>
                    <a href="start_conversation.php?user=<?= $profile_id ?>" class="btn-secondary">
                        <i data-lucide="mail" style="width:16px;height:16px;vertical-align:middle;"></i>
                        <?= __('profile_message_button') ?>
                    </a>
                <?php endif; ?>

                <!-- Block form: handleBlock() in interact.js shows the qarotaConfirm itself -->
                <form method="POST" action="block.php" class="block-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="target_id" value="<?= $profile_id ?>">
                    <input type="hidden" name="block_action"
                           value="<?= $i_blocked_them ? 'unblock' : 'block' ?>">
                    <input type="hidden" name="redirect"
                           value="profile.php?u=<?= urlencode($profile_user['username']) ?>">
                    <button type="submit"
                            class="<?= $i_blocked_them ? 'btn-secondary' : 'btn-danger' ?>">
                        <?= $i_blocked_them ? __('block_unblock') : __('block_button') ?>
                    </button>
                </form>

                <?php if ($viewer_is_admin && !$profile_is_admin && !$i_blocked_them): ?>
                    <?php if ($profile_is_verified): ?>
                        <!-- Generic confirm dispatcher handles data-confirm -->
                        <form method="POST" action="admin_verify.php" style="display:inline;"
                              data-confirm="Remove verification from @<?= htmlspecialchars($profile_user['username'], ENT_QUOTES) ?>?"
                              data-confirm-title="Remove verification"
                              data-confirm-ok="Remove"
                              data-confirm-danger="1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="target_id" value="<?= $profile_id ?>">
                            <input type="hidden" name="action" value="revoke">
                            <input type="hidden" name="admin_note" value="Verification removed via admin panel.">
                            <input type="hidden" name="redirect" value="profile.php?u=<?= urlencode($profile_user['username']) ?>">
                            <button type="submit" class="btn-secondary">
                                <i data-lucide="badge-x" style="width:14px;height:14px;vertical-align:middle;"></i>
                                Remove verification
                            </button>
                        </form>
                    <?php else: ?>
                        <button type="button" class="btn-secondary"
                                onclick="document.getElementById('verifyDialog').showModal();">
                            <i data-lucide="badge-check" style="width:14px;height:14px;vertical-align:middle;"></i>
                            Verify user
                        </button>
                    <?php endif; ?>

                    <?php if ($profile_is_banned): ?>
                        <form method="POST" action="admin_ban.php" style="display:inline;"
                              data-confirm="Unban @<?= htmlspecialchars($profile_user['username'], ENT_QUOTES) ?>?"
                              data-confirm-title="Unban user"
                              data-confirm-ok="Unban"
                              data-confirm-danger="1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="target_id" value="<?= $profile_id ?>">
                            <input type="hidden" name="action" value="unban">
                            <input type="hidden" name="admin_note" value="Unbanned via admin panel.">
                            <input type="hidden" name="redirect" value="profile.php?u=<?= urlencode($profile_user['username']) ?>">
                            <button type="submit" class="btn-secondary">Unban user</button>
                        </form>
                    <?php else: ?>
                        <button type="button" class="btn-danger" onclick="document.getElementById('banDialog').showModal();">
                            Ban user
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if ($i_blocked_them): ?>
                <p class="blocked-note"><?= __('block_you_blocked') ?></p>
            <?php elseif ($they_blocked_me): ?>
                <p class="blocked-note"><?= __('block_they_blocked') ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($flash_error): ?>
    <div class="message" style="border-left-color: var(--danger); background: #FDECEA;">
        <?= htmlspecialchars($flash_error) ?>
    </div>
<?php endif; ?>

<?php if ($flash_success): ?>
    <div class="message" style="border-left-color: var(--baobab-green); background: #E8F5E9;">
        <?= htmlspecialchars($flash_success) ?>
    </div>
<?php endif; ?>

<?php if ($avatar_message): ?>
    <div class="message"><?= htmlspecialchars($avatar_message) ?></div>
<?php endif; ?>

<?php if ($is_own_profile): ?>
    <div class="card">
        <h2 style="margin-top:0;"><?= __('post_share') ?></h2>
        <form id="compose-form"
              method="POST"
              enctype="multipart/form-data"
              class="stack"
              data-voice-max="180">
            <?= csrf_field() ?>
            <textarea name="content" maxlength="500" placeholder="<?= __('post_placeholder') ?>"></textarea>

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

            <div class="form-field">
                <label><?= __('post_visibility_label') ?></label>
                <label class="visibility-option">
                    <input type="radio" name="visibility" value="public"
                           <?= ($visibility ?? 'public') === 'public' ? 'checked' : '' ?>>
                    <i data-lucide="globe" class="visibility-icon"></i>
                    <span><?= __('post_visibility_public') ?></span>
                </label>
                <label class="visibility-option">
                    <input type="radio" name="visibility" value="followers"
                           <?= ($visibility ?? 'public') === 'followers' ? 'checked' : '' ?>>
                    <i data-lucide="users" class="visibility-icon"></i>
                    <span><?= __('post_visibility_followers') ?></span>
                </label>
                <label class="visibility-option">
                    <input type="radio" name="visibility" value="private"
                           <?= ($visibility ?? 'public') === 'private' ? 'checked' : '' ?>>
                    <i data-lucide="lock" class="visibility-icon"></i>
                    <span><?= __('post_visibility_private') ?></span>
                </label>
            </div>

            <div class="compose-media-row">
                <label for="post-image" class="file-picker">
                    <i data-lucide="image-plus" class="file-picker-icon"></i>
                    <span class="file-picker-text"><?= __('post_add_photo') ?></span>
                    <span class="file-picker-name" id="postFileName"></span>
                </label>
                <input type="file"
                       id="post-image"
                       name="image"
                       accept="image/jpeg,image/png,image/gif,image/webp"
                       class="file-picker-input"
                       onchange="var n = this.files[0] ? this.files[0].name : ''; document.getElementById('postFileName').textContent = n;">

                <label for="post-video" class="file-picker">
                    <i data-lucide="video" class="file-picker-icon"></i>
                    <span class="file-picker-text">Add a video</span>
                    <span class="file-picker-name" id="postVideoName"></span>
                </label>
                <input type="file"
                       id="post-video"
                       name="video"
                       accept="video/mp4,video/webm,video/quicktime"
                       class="file-picker-input"
                       onchange="handlePostVideoPick(this);">

                <input type="hidden" name="video_duration" id="postVideoDuration" value="0">

                <button type="button"
                        id="postMicBtn"
                        class="file-picker"
                        aria-label="Record voice note">
                    <i data-lucide="mic" class="file-picker-icon"></i>
                    <span class="file-picker-text">Record voice</span>
                </button>
            </div>

            <div id="postVoiceRecorder" class="voice-recorder" style="display:none;">
                <span class="voice-rec-dot"></span>
                <span id="postVoiceRecTime" class="voice-rec-time">0:00</span>
                <button type="button" id="postVoiceCancelBtn" class="voice-rec-cancel">Cancel</button>
                <button type="button" id="postVoiceSendBtn"   class="voice-rec-send">Attach</button>
            </div>

            <div id="postVoicePreview" class="post-voice-ready" hidden>
                <span class="post-voice-ready-dot"></span>
                <span class="post-voice-ready-text">Voice note attached</span>
                <button type="button" id="postVoiceDiscard" class="post-voice-ready-discard" aria-label="Discard recording">✕</button>
            </div>

            <input type="file"   name="voice"          id="postVoiceFile"     accept="audio/*" hidden>
            <input type="hidden" name="voice_duration" id="postVoiceDuration" value="0">

            <button type="submit"><?= __('post_button') ?></button>
        </form>
    </div>

    <!-- Upload progress overlay: OUTSIDE the form, NOT inside any hidden wrapper. -->
    <div id="videoUploadOverlay" class="upload-overlay" hidden>
        <div class="upload-card">
            <h3 id="videoUploadTitle">Uploading video…</h3>

            <div class="upload-progress-track">
                <div class="upload-progress-fill" id="videoUploadFill"></div>
            </div>
            <div class="upload-progress-meta">
                <span id="videoUploadPct">0%</span>
                <span id="videoUploadSpeed"></span>
            </div>

            <p class="upload-hint" id="videoUploadHint">
                Keep this tab open. Don't close the app.
            </p>

            <div class="upload-actions">
                <button type="button" id="videoUploadCancel" class="btn-secondary">Cancel</button>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (empty($posts)): ?>
    <div class="empty"><?= __('profile_no_posts') ?></div>
<?php else: ?>
    <?php foreach ($posts as $post): ?>
        <?php
        $media_type = $post['media_type'] ?? 'text';
        $has_voice  = $media_type === 'voice' && !empty($post['media_path']);
        $has_image  = $media_type === 'image' && !empty($post['media_path']);
        $has_video  = $media_type === 'video' && !empty($post['media_path']);
        ?>
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
                        <?= htmlspecialchars($display_name) ?><?= verification_badge($post['verification_type'] ?? 'none', 12) ?>
                    </a>
                </div>
            </div>

            <?php if (trim((string)$post['content']) !== ''): ?>
                <div class="content"><?= htmlspecialchars($post['content']) ?></div>
            <?php endif; ?>

            <?php if (!empty($topics_by_post[$post['id']])): ?>
                <div style="margin: var(--space-2) 0;">
                    <?php foreach ($topics_by_post[$post['id']] as $t): ?>
                        <a href="topic.php?slug=<?= urlencode($t['slug']) ?>" class="topic-tag">
                            #<?= htmlspecialchars($t['name']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($has_video): ?>
                <?php if (!empty($_SESSION['low_data_mode'])): ?>
                    <div class="img-placeholder video-placeholder"
                         data-video-src="post_video.php?id=<?= (int)$post['id'] ?>">
                        <i data-lucide="video"></i>
                        <span>Tap to load video</span>
                    </div>
                <?php else: ?>
                    <?php
                    $vw = (int)($post['video_width']  ?? 0);
                    $vh = (int)($post['video_height'] ?? 0);
                    $orientation = ($vw > 0 && $vh > 0)
                        ? ($vw >= $vh ? 'landscape' : 'portrait')
                        : 'unknown';
                    ?>
                    <video class="post-video post-video-<?= $orientation ?>"
                           controls
                           preload="metadata"
                           playsinline
                           <?php if (!empty($post['video_thumb'])): ?>
                               poster="post_video_thumb.php?id=<?= (int)$post['id'] ?>"
                           <?php endif; ?>
                           data-video-id="<?= (int)$post['id'] ?>"
                           data-orientation="<?= $orientation ?>">
                        <source src="post_video.php?id=<?= (int)$post['id'] ?>"
                                type="<?= htmlspecialchars($post['media_mime'] ?: 'video/mp4') ?>">
                    </video>
                <?php endif; ?>
            <?php elseif ($has_voice): ?>
                <div class="post-voice" data-duration="<?= (int)($post['media_duration'] ?? 0) ?>">
                    <button type="button" class="post-voice-play" aria-label="Play voice note">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <polygon points="6 4 20 12 6 20 6 4"/>
                        </svg>
                    </button>
                    <div class="post-voice-wave" aria-hidden="true"></div>
                    <span class="post-voice-time"><?= gmdate('i:s', (int)($post['media_duration'] ?? 0)) ?></span>
                    <audio preload="none" src="voice_serve.php?type=post&id=<?= (int)$post['id'] ?>"></audio>
                </div>
            <?php elseif ($has_image): ?>
                <?php if (!empty($_SESSION['low_data_mode'])): ?>
                    <div class="img-placeholder" data-src="post_media.php?id=<?= (int)$post['id'] ?>">
                        <i data-lucide="image"></i>
                        <span>Tap to load image</span>
                    </div>
                <?php else: ?>
                    <img class="media" src="post_media.php?id=<?= (int)$post['id'] ?>" alt="Post image">
                <?php endif; ?>
            <?php endif; ?>

            <div class="meta">
                <?= htmlspecialchars($post['created_at']) ?>
                <?php if (!empty($post['edited_at'])): ?>
                    · <span class="edited-label" title="Edited <?= htmlspecialchars($post['edited_at']) ?>">Edited</span>
                <?php endif; ?>
                <?php if ($is_own_profile): ?>
                    ·
                    <span class="view-count" title="<?= (int)$post['view_count'] ?> people saw this">
                        <i data-lucide="eye" style="width:13px;height:13px;"></i>
                        <?= (int)$post['view_count'] ?>
                    </span>
                <?php endif; ?>
            </div>
            <?php $post_visibility = $post['visibility'] ?? 'public'; ?>
            <?php if ($post_visibility !== 'public'): ?>
                <span class="post-visibility-badge"
                      title="<?= __('post_visibility_' . $post_visibility . '_title') ?>">
                    <i data-lucide="<?= $post_visibility === 'private' ? 'lock' : 'users' ?>"
                       style="width:12px;height:12px;"></i>
                    <?= __('post_visibility_' . $post_visibility . '_badge') ?>
                </span>
            <?php endif; ?>

            <?php if ($blocked_either): ?>
                <div class="actions">
                    <span class="like-btn" style="opacity:0.5; cursor:not-allowed;" title="<?= __('block_cannot_interact') ?>">
                        <span class="like-heart">♡</span>
                        <span class="like-count"><?= (int)$post['like_count'] ?></span>
                    </span>
                    <span class="comment-count">
                        <i data-lucide="message-circle" style="width:14px;height:14px;"></i>
                        <?= (int)$post['comment_count'] ?>
                    </span>
                    <span class="bookmark-btn" style="opacity:0.5; cursor:not-allowed;">
                        <i data-lucide="bookmark" class="bookmark-icon"></i>
                    </span>
                    <a href="report.php?post_id=<?= $post['id'] ?>" class="report-link"><?= __('post_report') ?></a>
                </div>
            <?php else: ?>
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
                        <!-- Delete-post: handleDeletePostSubmit() in interact.js shows the qarotaConfirm itself -->
                        <form method="POST"
                              action="delete_post.php"
                              class="delete-post-form"
                              style="display:inline; margin:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                            <input type="hidden" name="redirect" value="<?= htmlspecialchars('profile.php?u=' . $profile_user['username']) ?>">
                            <button type="submit" class="delete-link" title="Delete post">
                                <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
                                Delete
                            </button>
                        </form>
                    <?php else: ?>
                        <a href="report.php?post_id=<?= $post['id'] ?>" class="report-link"><?= __('post_report') ?></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

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
                                    <strong><?= htmlspecialchars($comment_name) ?></strong><?= verification_badge($c['verification_type'] ?? 'none', 11) ?>
                                </a>
                            </div>
                            <?= htmlspecialchars($c['content']) ?>
                            <div class="meta" style="display:flex; justify-content:space-between; align-items:center;">
                                <span><?= htmlspecialchars($c['created_at']) ?></span>
                                <?php if ((int)$c['user_id'] !== (int)$_SESSION['user_id']): ?>
                                    <a href="report.php?comment_id=<?= $c['id'] ?>" class="report-link" style="font-size:0.75rem;">
                                        Report
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($is_own_profile || !$blocked_either): ?>
                <form method="POST" action="interact.php" class="comment-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="comment">
                    <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                    <input type="hidden" name="redirect" value="profile.php?u=<?= urlencode($profile_user['username']) ?>">
                    <input type="text" name="content" placeholder="<?= __('post_comment_placeholder') ?>" maxlength="500" required>
                    <button type="submit"><?= __('post_send') ?></button>
                </form>
            <?php else: ?>
                <p class="blocked-note" style="font-size: 0.85rem;">
                    <?= __('block_cannot_comment') ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($viewer_is_admin && !$is_own_profile && !$profile_is_admin && !$profile_is_banned && !$profile_is_verified): ?>
    <dialog id="verifyDialog" class="qarota-dialog">
        <form method="POST" action="admin_verify.php" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="target_id" value="<?= $profile_id ?>">
            <input type="hidden" name="action" value="grant">
            <input type="hidden" name="redirect" value="profile.php?u=<?= urlencode($profile_user['username']) ?>">

            <h2 style="margin-top:0;">Verify @<?= htmlspecialchars($profile_user['username']) ?></h2>
            <p style="color:var(--muted); font-size:0.9rem;">
                Only grant verification after confirming identity. This decision is logged publicly.
            </p>

            <div class="form-field">
                <label for="verify_type">Verification type</label>
                <select id="verify_type" name="verification_type" required>
                    <option value="personal">Personal — individual identity confirmed</option>
                    <option value="organization">Organization — business, NGO, or media</option>
                    <option value="staff">Qarota staff</option>
                </select>
            </div>

            <div class="form-field">
                <label for="verify_note">Note (shown publicly)</label>
                <textarea id="verify_note" name="admin_note" maxlength="500" rows="3" required
                          placeholder="Explain how identity was confirmed…"></textarea>
            </div>

            <div style="display:flex; gap: var(--space-2); margin-top: var(--space-3);">
                <button type="submit">Grant verification</button>
                <button type="button" class="btn-secondary"
                        onclick="document.getElementById('verifyDialog').close();">Cancel</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<?php if ($viewer_is_admin && !$is_own_profile && !$profile_is_admin && !$profile_is_banned): ?>
    <dialog id="banDialog" class="qarota-dialog">
        <form method="POST" action="admin_ban.php" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="target_id" value="<?= $profile_id ?>">
            <input type="hidden" name="action" value="ban">
            <input type="hidden" name="redirect" value="profile.php?u=<?= urlencode($profile_user['username']) ?>">

            <h2 style="margin-top:0;">Ban @<?= htmlspecialchars($profile_user['username']) ?></h2>
            <p style="color:var(--muted); font-size:0.9rem;">
                This user will lose access immediately. The reason and your note will appear in the public moderation log.
            </p>

            <div class="form-field">
                <label for="ban_category">Policy category</label>
                <select id="ban_category" name="policy_category" required>
                    <option value="">— Choose —</option>
                    <option value="spam">Spam</option>
                    <option value="harassment">Harassment</option>
                    <option value="hate_speech">Hate speech</option>
                    <option value="misinformation">Misinformation</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <div class="form-field">
                <label for="ban_note">Note (shown publicly)</label>
                <textarea id="ban_note" name="admin_note" maxlength="500" rows="4" required
                          placeholder="Brief explanation of why you're banning this user…"></textarea>
            </div>

            <div style="display:flex; gap: var(--space-2); margin-top: var(--space-3);">
                <button type="submit" class="btn-danger">Confirm ban</button>
                <button type="button" class="btn-secondary"
                        onclick="document.getElementById('banDialog').close();">Cancel</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>


<script>
/* ============================================================
   POST VIDEO — pick handler + upload with progress overlay
   ============================================================ */

var postVideoState = {
    picked: false,
    duration: 0
};

function handlePostVideoPick(input) {
    var label = document.getElementById('postVideoName');
    var durIn = document.getElementById('postVideoDuration');
    var file  = input.files && input.files[0];

    if (!file) {
        if (label) label.textContent = '';
        if (durIn) durIn.value = 0;
        postVideoState.picked = false;
        postVideoState.duration = 0;
        return;
    }

    var MAX_MB    = 650;
    var MAX_BYTES = MAX_MB * 1024 * 1024;

    if (file.size > MAX_BYTES) {
        qarotaAlert(
            'That video is too large to upload (max ' + MAX_MB + ' MB).\n\nTry a shorter clip.',
            { title: 'Video too large' }
        );
        input.value = '';
        if (label) label.textContent = '';
        if (durIn) durIn.value = 0;
        postVideoState.picked = false;
        postVideoState.duration = 0;
        return;
    }

    if (label) label.textContent = file.name;

    var probe = document.createElement('video');
    probe.preload = 'metadata';
    probe.onloadedmetadata = function () {
        var dur = Math.round(probe.duration) || 0;
        if (durIn) durIn.value = dur;
        postVideoState.duration = dur;
        URL.revokeObjectURL(probe.src);

        var MAX_SEC = 1200;
        if (probe.duration > MAX_SEC + 1) {
            var totalSec    = Math.round(probe.duration);
            var mins        = Math.floor(totalSec / 60);
            var secs        = totalSec % 60;
            var durationStr = mins + ':' + ('0' + secs).slice(-2);

            var overBySec = totalSec - MAX_SEC;
            var overMin   = Math.floor(overBySec / 60);
            var overSec   = overBySec % 60;
            var overStr   = overMin > 0
                ? overMin + ' min ' + overSec + ' sec'
                : overSec + ' sec';

            qarotaAlert(
                'This video is ' + durationStr + ' long.\n\n' +
                'Videos can be up to 20:00 on Qarota. ' +
                'You need to trim about ' + overStr + '.\n\n' +
                'Tip: use your phone\'s built-in trimmer or a free tool like CapCut to shorten it, then try again.',
                { title: 'Video is too long' }
            );
            input.value = '';
            if (label) label.textContent = '';
            if (durIn) durIn.value = 0;
            postVideoState.picked = false;
            postVideoState.duration = 0;
            return;
        }

        postVideoState.picked = true;
    };
    probe.onerror = function () {
        URL.revokeObjectURL(probe.src);
        postVideoState.picked = true;
    };
    probe.src = URL.createObjectURL(file);
}

/* ============================================================
   VIDEO UPLOAD — with progress + speed + cancel
   Only runs when a video is attached. Text/image/voice posts
   continue to use the browser's native form POST.
   ============================================================ */
(function () {
    var form = document.getElementById('compose-form');
    if (!form) return;

    var videoInput = document.getElementById('post-video');
    if (!videoInput) return;

    form.addEventListener('submit', function (e) {
        if (!postVideoState.picked) return;
        var file = videoInput.files && videoInput.files[0];
        if (!file) return;

        e.preventDefault();
        uploadVideoPost(file);
    });

    function uploadVideoPost(file) {
        var overlay   = document.getElementById('videoUploadOverlay');
        var fill      = document.getElementById('videoUploadFill');
        var pctEl     = document.getElementById('videoUploadPct');
        var speedEl   = document.getElementById('videoUploadSpeed');
        var titleEl   = document.getElementById('videoUploadTitle');
        var hintEl    = document.getElementById('videoUploadHint');
        var cancelBtn = document.getElementById('videoUploadCancel');

        var xhr = new XMLHttpRequest();
        var lastLoaded = 0;
        var lastTime   = 0;
        var cancelled  = false;
        var processingTimer = null;

        function startProcessingAnimation() {
            if (processingTimer) return;
            var pct = 95;
            processingTimer = setInterval(function () {
                // Never cross 99 — we go to 100 only when the server actually responds.
                if (pct < 99) {
                    pct += (99 - pct) * 0.08;
                    setProgress(pct);
                }
            }, 400);
        }
        function stopProcessingAnimation() {
            if (processingTimer) {
                clearInterval(processingTimer);
                processingTimer = null;
            }
        }

        function show() {
            if (overlay) overlay.hidden = false;
            if (fill) fill.style.width = '0%';
            if (pctEl) pctEl.textContent = '0%';
            if (speedEl) speedEl.textContent = '';
            if (titleEl) titleEl.textContent = 'Uploading video…';
            if (hintEl) hintEl.textContent = "Keep this tab open. Don't close the app.";
            if (cancelBtn) cancelBtn.disabled = false;
            document.body.style.overflow = 'hidden';
        }
        function hide() {
            if (overlay) overlay.hidden = true;
            document.body.style.overflow = '';
        }
        function setProgress(pct) {
            if (fill) fill.style.width = pct + '%';
            if (pctEl) pctEl.textContent = Math.floor(pct) + '%';
        }
        function fmtSpeed(bps) {
            if (bps < 1024) return bps.toFixed(0) + ' B/s';
            if (bps < 1024 * 1024) return (bps / 1024).toFixed(1) + ' KB/s';
            return (bps / (1024 * 1024)).toFixed(1) + ' MB/s';
        }

        function onCancel() {
            if (cancelled) return;
            cancelled = true;
            stopProcessingAnimation();
            if (cancelBtn) cancelBtn.disabled = true;
            if (titleEl) titleEl.textContent = 'Cancelling…';
            try { xhr.abort(); } catch (err) {}
        }
        if (cancelBtn) {
            cancelBtn.addEventListener('click', onCancel, { once: true });
        }

        var fd = new FormData(form);

        xhr.open('POST', window.location.href, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.upload.onprogress = function (e) {
            if (!e.lengthComputable) return;
            var pct = (e.loaded / e.total) * 90;
            setProgress(pct);

            var now = performance.now();
            if (lastTime > 0 && e.loaded > lastLoaded) {
                var dt = (now - lastTime) / 1000;
                var db = e.loaded - lastLoaded;
                if (dt > 0.25 && speedEl) {
                    speedEl.textContent = fmtSpeed(db / dt);
                }
            }
            lastLoaded = e.loaded;
            lastTime = now;

            if (e.loaded >= e.total && titleEl) {
                titleEl.textContent = 'Processing video…';
                if (hintEl) hintEl.textContent = "Preparing your video for playback. This can take up to a minute.";
            }
        };

        xhr.upload.onload = function () {
            if (cancelled) return;
            setProgress(95);
            startProcessingAnimation();

            if (titleEl) titleEl.textContent = 'Processing video…';
            if (hintEl) hintEl.textContent = 'Preparing your video for playback. This can take up to a minute.';

            var messages = [
                'Compressing video…',
                'Generating thumbnail…',
                'Still working — larger videos take longer…',
                'Almost done…'
            ];
            var idx = 0;
            var rotator = setInterval(function () {
                idx++;
                if (idx >= messages.length) idx = messages.length - 1;
                if (hintEl) hintEl.textContent = messages[idx];
            }, 6000);

            var stopRotator = function () { clearInterval(rotator); };
            xhr.addEventListener('load', stopRotator, { once: true });
            xhr.addEventListener('error', stopRotator, { once: true });
            xhr.addEventListener('abort', stopRotator, { once: true });
            xhr.addEventListener('timeout', stopRotator, { once: true });
        };

        xhr.onload = function () {
            if (cancelled) return;
            stopProcessingAnimation();
            setProgress(100);

            setTimeout(function () {
                hide();
                window.location.reload();
            }, 300);
        };

        xhr.onerror = function () {
            if (cancelled) return;
            stopProcessingAnimation();
            hide();
            qarotaAlert(
                'Network error during upload. Check your connection and try again.',
                { title: 'Upload failed' }
            );
        };

        xhr.onabort = function () {
            stopProcessingAnimation();
            hide();
        };

        xhr.ontimeout = function () {
            if (cancelled) return;
            stopProcessingAnimation();
            hide();
            qarotaAlert(
                'Upload timed out. Try a smaller video or a stronger connection.',
                { title: 'Upload timed out' }
            );
        };

        xhr.timeout = 0;

        show();
        xhr.send(fd);
    }
})();

/* ============================================================
   GENERIC CONFIRM DISPATCHER
   Any <form data-confirm="..."> is intercepted, confirmed via
   qarotaConfirm(), and submitted natively if the user agrees.
   ============================================================ */
document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.dataset || !form.dataset.confirm) return;
    if (form.dataset.confirmHandled === '1') return;

    e.preventDefault();
    form.dataset.confirmHandled = '1';

    qarotaConfirm(form.dataset.confirm, {
        title:      form.dataset.confirmTitle || 'Are you sure?',
        okText:     form.dataset.confirmOk    || 'Confirm',
        cancelText: 'Cancel',
        danger:     form.dataset.confirmDanger === '1'
    }).then(function (ok) {
        form.dataset.confirmHandled = '0';
        if (ok) form.submit();
    });
});
</script>

<?php require 'includes/footer.php'; ?>