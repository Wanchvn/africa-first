<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$post_id = (int)($_GET['id'] ?? 0);

if ($post_id <= 0) {
    header('Location: feed.php');
    exit;
}

// Fetch the post
$stmt = $pdo->prepare("
    SELECT id, user_id, content, media_path, created_at, edited_at
    FROM posts
    WHERE id = :id
");
$stmt->execute([':id' => $post_id]);
$post = $stmt->fetch();

if (!$post) {
    header('Location: feed.php');
    exit;
}

// Only the author can edit
if ((int)$post['user_id'] !== $user_id) {
    header('Location: feed.php');
    exit;
}

// Don't allow editing posts older than 30 days
$thirty_days_ago = strtotime('-30 days');
if (strtotime($post['created_at']) < $thirty_days_ago) {
    header('Location: profile.php');
    exit;
}

$message = '';
$errors = [];
$content = $post['content'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    rate_limit_enforce($pdo, client_ip(), 'edit_post', 20, 300);

    $content = trim($_POST['content'] ?? '');

    if ($content === '') {
        $errors[] = 'Post content cannot be empty.';
    } elseif (mb_strlen($content) > 500) {
        $errors[] = 'Post must be 500 characters or less.';
    } elseif ($content === $post['content']) {
        $errors[] = 'You haven\'t changed anything yet.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            UPDATE posts
            SET content = :content, edited_at = NOW()
            WHERE id = :id AND user_id = :user_id
        ");
        $stmt->execute([
            ':content' => $content,
            ':id'      => $post_id,
            ':user_id' => $user_id,
        ]);

        // Redirect back to where the user came from
        $redirect = $_POST['redirect'] ?? 'profile.php';
        $allowed_redirects = ['feed.php', 'profile.php', 'search.php', 'discover_feed.php', 'topic.php', 'saved.php'];
        $redirect_base = strtok($redirect, '?');
        if (!in_array($redirect_base, $allowed_redirects, true)) {
            $redirect = 'profile.php';
        }

        header('Location: ' . $redirect);
        exit;
    }
}

$page_title = 'Edit post';
require 'includes/header.php';
?>

<h1>Edit post</h1>

<?php if (!empty($errors)): ?>
    <div class="message" style="border-left-color: #c0392b; background: #FDECEA;">
        <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card">
    <form method="POST" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($_GET['from'] ?? 'profile.php') ?>">

        <div class="form-field">
            <label for="content">Post content</label>
            <textarea id="content"
                      name="content"
                      maxlength="500"
                      oninput="document.getElementById('charCount').textContent = 500 - this.value.length"
                      required><?= htmlspecialchars($content) ?></textarea>
            <div class="field-hint" style="text-align:right;">
                <span id="charCount"><?= 500 - mb_strlen($content) ?></span> characters left
            </div>
        </div>

        <?php if ($post['media_path']): ?>
            <div class="form-field">
                <label>Attached photo</label>
                <img class="media" src="<?= htmlspecialchars($post['media_path']) ?>" alt="">
                <div class="field-hint">To change the photo, delete this post and create a new one.</div>
            </div>
        <?php endif; ?>

        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button type="submit">Save changes</button>
            <a href="<?= htmlspecialchars($_GET['from'] ?? 'profile.php') ?>" class="btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<p style="text-align:center; font-size:0.85rem; color: var(--muted);">
    Edited posts show an "Edited" label. Original content isn't saved.
</p>

<?php require 'includes/footer.php'; ?>