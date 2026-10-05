<?php
session_start();
require 'config/db.php';
require 'lang/init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$profile_id = isset($_GET['id']) ? (int)$_GET['id'] : (int)$_SESSION['user_id'];
$is_own_profile = ($profile_id === (int)$_SESSION['user_id']);

$stmt = $pdo->prepare("SELECT username, avatar FROM users WHERE id = :id");
$stmt->execute([':id' => $profile_id]);
$profile_user = $stmt->fetch();

if (!$profile_user) {
    header('Location: feed.php');
    exit;
}

$is_following = false;
if (!$is_own_profile) {
    $stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = :me AND following_id = :them");
    $stmt->execute([':me' => $_SESSION['user_id'], ':them' => $profile_id]);
    $is_following = (bool)$stmt->fetch();
}

$message = '';
$avatar_message = $_SESSION['avatar_message'] ?? '';
unset($_SESSION['avatar_message']);

if ($is_own_profile && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty(trim($_POST['content']))) {
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
        $message = __('post_published');
    }
}

$stmt = $pdo->prepare("
    SELECT
        posts.id, posts.content, posts.media_path, posts.created_at,
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

$comments_by_post = [];
if (!empty($posts)) {
    $post_ids = array_column($posts, 'id');
    $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT comments.post_id, comments.content, comments.created_at, users.username, users.avatar
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

$page_title = $profile_user['username'];
require 'includes/header.php';
?>

<div class="profile-header">
    <?php if ($profile_user['avatar']): ?>
        <img class="avatar avatar-large"
             src="<?= htmlspecialchars($profile_user['avatar']) ?>"
             alt="Profile picture">
    <?php else: ?>
        <div class="avatar avatar-large avatar-placeholder">
            <?= strtoupper(substr($profile_user['username'], 0, 1)) ?>
        </div>
    <?php endif; ?>

    <div class="profile-info">
        <h1>
            <?= $is_own_profile ? __('profile_hello') . ' ' : '' ?>
            <?= htmlspecialchars($profile_user['username']) ?>
        </h1>

        <?php if ($is_own_profile): ?>
            <div class="profile-actions">
                <form method="POST" action="avatar.php" enctype="multipart/form-data" style="display:inline;">
                    <label for="avatar" class="btn-secondary"><?= __('profile_change_picture') ?></label>
                    <input type="file" id="avatar" name="avatar"
                           accept="image/jpeg,image/png,image/gif,image/webp"
                           onchange="this.form.submit()" style="display:none;">
                </form>
                <a href="exportar.php" class="btn-secondary"><?= __('profile_export') ?></a>
                <a href="privacidad.php" class="btn-secondary"><?= __('profile_privacy') ?></a>
                <a href="eliminar_cuenta.php" class="btn-danger"><?= __('profile_delete') ?></a>
            </div>
        <?php else: ?>
            <form method="POST" action="seguir.php">
                <input type="hidden" name="target_id" value="<?= $profile_id ?>">
                <input type="hidden" name="redirect" value="perfil.php?id=<?= $profile_id ?>">
                <button type="submit" class="<?= $is_following ? 'btn-secondary' : '' ?>">
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
            <textarea name="content" maxlength="500" placeholder="<?= __('post_placeholder') ?>" required></textarea>
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
                    <img class="avatar avatar-small"
                         src="<?= htmlspecialchars($post['avatar']) ?>"
                         alt="">
                <?php else: ?>
                    <div class="avatar avatar-small avatar-placeholder">
                        <?= strtoupper(substr($profile_user['username'], 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <div class="author">
                    <a href="perfil.php?id=<?= $profile_id ?>">
                        <?= htmlspecialchars($profile_user['username']) ?>
                    </a>
                </div>
            </div>

            <div class="content"><?= htmlspecialchars($post['content']) ?></div>

            <?php if ($post['media_path']): ?>
                <img class="media" src="<?= htmlspecialchars($post['media_path']) ?>" alt="Post image">
            <?php endif; ?>

            <div class="meta"><?= htmlspecialchars($post['created_at']) ?></div>

            <div class="actions">
                <form method="POST" action="interactuar.php" style="display:inline;">
                    <input type="hidden" name="action" value="like">
                    <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                    <input type="hidden" name="redirect" value="perfil.php?id=<?= $profile_id ?>">
                    <button type="submit" class="like-btn <?= $post['liked_by_me'] ? 'liked' : '' ?>">
                        <?= $post['liked_by_me'] ? '♥' : '♡' ?>
                        <?= (int)$post['like_count'] ?>
                    </button>
                </form>
                <span class="comment-count">💬 <?= (int)$post['comment_count'] ?></span>
                <?php if (!$is_own_profile): ?>
                    <a href="reportar.php?post_id=<?= $post['id'] ?>" class="report-link"><?= __('post_report') ?></a>
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

            <form method="POST" action="interactuar.php" class="comment-form">
                <input type="hidden" name="action" value="comment">
                <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                <input type="hidden" name="redirect" value="perfil.php?id=<?= $profile_id ?>">
                <input type="text" name="content" placeholder="<?= __('post_comment_placeholder') ?>" maxlength="500" required>
                <button type="submit"><?= __('post_send') ?></button>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>