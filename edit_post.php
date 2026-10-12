<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require_once __DIR__ . '/includes/posts.php';

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
$edit_visibility_sql = post_visibility_filter('posts', 'edit_visibility');
$stmt = $pdo->prepare("
    SELECT id, user_id, content, media_path, created_at, edited_at, visibility
    FROM posts
    WHERE id = :id AND user_id = :viewer_id
      AND $edit_visibility_sql
");
$stmt->execute(array_merge(
    [':id' => $post_id, ':viewer_id' => $user_id],
    post_visibility_params($user_id, 'edit_visibility')
));
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
$visibility = $post['visibility'] ?? 'public';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    rate_limit_enforce($pdo, client_ip(), 'edit_post', 30, 3600);

    $content = trim($_POST['content'] ?? '');
    $visibility = $_POST['visibility'] ?? 'public';
    if (!in_array($visibility, ['public', 'followers', 'private'], true)) {
        $visibility = 'public';
    }

    if ($content === '') {
        $errors[] = 'Post content cannot be empty.';
    } elseif (mb_strlen($content) > 500) {
        $errors[] = 'Post must be 500 characters or less.';
    } elseif ($content === $post['content'] && $visibility === ($post['visibility'] ?? 'public')) {
        $errors[] = 'You haven\'t changed anything yet.';
    }

    if (empty($errors)) {
        $mediaMove = null;
        $updatedMediaPath = $post['media_path'];
        if (
            $visibility !== 'public'
            && !empty($post['media_path'])
            && !str_starts_with($post['media_path'], 'uploads/private_posts/')
        ) {
            $mediaMove = move_post_media_to_private($post['media_path']);
            $updatedMediaPath = $mediaMove['media_path'];
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                UPDATE posts
                SET content = :content, visibility = :visibility, media_path = :media_path,
                    edited_at = NOW()
                WHERE id = :id AND user_id = :user_id
            ");
            $stmt->execute([
                ':content' => $content,
                ':visibility' => $visibility,
                ':media_path' => $updatedMediaPath,
                ':id' => $post_id,
                ':user_id' => $user_id,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (
                $mediaMove !== null
                && $mediaMove['source_path'] !== null
                && $mediaMove['destination_path'] !== null
                && file_exists($mediaMove['destination_path'])
                && !rename($mediaMove['destination_path'], $mediaMove['source_path'])
            ) {
                throw new RuntimeException('Could not restore post media after a failed update.', 0, $e);
            }
            throw $e;
        }

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
                <img class="media" src="post_media.php?id=<?= (int)$post['id'] ?>" alt="">
                <div class="field-hint">To change the photo, delete this post and create a new one.</div>
            </div>
        <?php endif; ?>

        <div class="form-field">
            <label><?= __('post_visibility_label') ?></label>
            <label class="visibility-option">
                <input type="radio" name="visibility" value="public"
                       <?= $visibility === 'public' ? 'checked' : '' ?>>
                <i data-lucide="globe" class="visibility-icon"></i>
                <span><?= __('post_visibility_public') ?></span>
            </label>
            <label class="visibility-option">
                <input type="radio" name="visibility" value="followers"
                       <?= $visibility === 'followers' ? 'checked' : '' ?>>
                <i data-lucide="users" class="visibility-icon"></i>
                <span><?= __('post_visibility_followers') ?></span>
            </label>
            <label class="visibility-option">
                <input type="radio" name="visibility" value="private"
                       <?= $visibility === 'private' ? 'checked' : '' ?>>
                <i data-lucide="lock" class="visibility-icon"></i>
                <span><?= __('post_visibility_private') ?></span>
            </label>
        </div>

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