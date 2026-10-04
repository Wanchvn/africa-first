<?php
session_start();
require 'config/db.php';

// Block unauthenticated users
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Determine whose profile we're viewing
$profile_id = isset($_GET['id']) ? (int)$_GET['id'] : $_SESSION['user_id'];
$is_own_profile = ($profile_id === (int)$_SESSION['user_id']);

// Fetch the profile owner's info
$stmt = $pdo->prepare("SELECT username FROM users WHERE id = :id");
$stmt->execute([':id' => $profile_id]);
$profile_user = $stmt->fetch();

// If user doesn't exist, bail out
if (!$profile_user) {
    header('Location: feed.php');
    exit;
}

$message = '';

// Handle new post submission — only allowed on your own profile
if ($is_own_profile && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty(trim($_POST['content']))) {
    $content = trim($_POST['content']);
    $media_path = null;

    // Handle image upload if present
    if (!empty($_FILES['image']['name'])) {
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $max_size = 5 * 1024 * 1024; // 5 MB

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

// Fetch posts for the profile being viewed
$stmt = $pdo->prepare("SELECT content, media_path, created_at FROM posts WHERE user_id = :user_id ORDER BY created_at DESC");
$stmt->execute([':user_id' => $profile_id]);
$posts = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head><title><?= htmlspecialchars($profile_user['username']) ?> — Baobab</title></head>
<body>

<p>
    <a href="feed.php">Feed</a> |
    <a href="descubrir.php">Discover People</a> |
    <a href="perfil.php">My Profile</a> |
    <a href="logout.php">Log out</a>
</p>

<h1>
    <?= $is_own_profile ? 'Hello, ' : '' ?>
    <?= htmlspecialchars($profile_user['username']) ?>
</h1>

<?php if ($message): ?><p><?= htmlspecialchars($message) ?></p><?php endif; ?>

<?php if ($is_own_profile): ?>
    <h2>Share something</h2>
    <form method="POST" enctype="multipart/form-data">
        <textarea name="content" maxlength="500" placeholder="What's on your mind?" required></textarea><br>
        <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp"><br>
        <button type="submit">Post</button>
    </form>
    <h2>Your posts</h2>
<?php else: ?>
    <h2>Posts by <?= htmlspecialchars($profile_user['username']) ?></h2>
<?php endif; ?>

<?php if (empty($posts)): ?>
    <p>No posts yet.</p>
<?php else: ?>
    <?php foreach ($posts as $post): ?>
        <div style="border:1px solid #ccc; padding:8px; margin:8px 0;">
            <p><?= nl2br(htmlspecialchars($post['content'])) ?></p>
            <?php if ($post['media_path']): ?>
                <img src="<?= htmlspecialchars($post['media_path']) ?>"
                     style="max-width: 400px; display: block;"
                     alt="Post image">
            <?php endif; ?>
            <small><?= htmlspecialchars($post['created_at']) ?></small>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

</body>
</html>