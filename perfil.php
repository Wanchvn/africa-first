<?php
session_start();
require 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$profile_id = isset($_GET['id']) ? (int)$_GET['id'] : (int)$_SESSION['user_id'];
$is_own_profile = ($profile_id === (int)$_SESSION['user_id']);

$stmt = $pdo->prepare("SELECT username FROM users WHERE id = :id");
$stmt->execute([':id' => $profile_id]);
$profile_user = $stmt->fetch();

if (!$profile_user) {
    header('Location: feed.php');
    exit;
}

$message = '';

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
            $message = 'Only JPG, PNG, GIF, or WebP images are allowed.';
        } elseif ($_FILES['image']['size'] > $max_size) {
            $message = 'Image must be smaller than 5 MB.';
        } elseif ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $message = 'Upload failed. Try again.';
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
                $message = 'Could not save the file.';
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
        $message = 'Post published.';
    }
}

$stmt = $pdo->prepare("SELECT content, media_path, created_at FROM posts WHERE user_id = :user_id ORDER BY created_at DESC");
$stmt->execute([':user_id' => $profile_id]);
$posts = $stmt->fetchAll();

$page_title = $profile_user['username'];
require 'includes/header.php';
?>

<h1>
    <?= $is_own_profile ? 'Hello, ' : '' ?>
    <?= htmlspecialchars($profile_user['username']) ?>
</h1>

<?php if ($message): ?>
    <div class="message"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if ($is_own_profile): ?>
    <div class="card">
        <h2 style="margin-top:0;">Share something</h2>
        <form method="POST" enctype="multipart/form-data" class="stack">
            <textarea name="content" maxlength="500" placeholder="What's on your mind?" required></textarea>
            <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
            <button type="submit">Post</button>
        </form>
    </div>
<?php endif; ?>

<?php if (empty($posts)): ?>
    <div class="empty">No posts yet.</div>
<?php else: ?>
    <?php foreach ($posts as $post): ?>
        <div class="card">
            <div class="author"><?= htmlspecialchars($profile_user['username']) ?></div>
            <div class="content"><?= htmlspecialchars($post['content']) ?></div>
            <?php if ($post['media_path']): ?>
                <img class="media" src="<?= htmlspecialchars($post['media_path']) ?>" alt="Post image">
            <?php endif; ?>
            <div class="meta"><?= htmlspecialchars($post['created_at']) ?></div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>