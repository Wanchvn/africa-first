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

$user_id = (int)$_SESSION['user_id'];
$folder_filter = isset($_GET['folder']) ? (int)$_GET['folder'] : null;
$show_default = ($folder_filter === 0);

// Flash message
$folder_message = $_SESSION['folder_message'] ?? '';
unset($_SESSION['folder_message']);

// Fetch all folders for sidebar
$stmt = $pdo->prepare("
    SELECT
        f.id, f.name,
        (SELECT COUNT(*) FROM bookmarks WHERE folder_id = f.id AND user_id = :me) AS count
    FROM bookmark_folders f
    WHERE f.user_id = :me2
    ORDER BY f.name ASC
");
$stmt->execute([':me' => $user_id, ':me2' => $user_id]);
$folders = $stmt->fetchAll();

// Count of default (NULL folder) bookmarks
$stmt = $pdo->prepare("SELECT COUNT(*) FROM bookmarks WHERE user_id = :me AND folder_id IS NULL");
$stmt->execute([':me' => $user_id]);
$default_count = (int)$stmt->fetchColumn();

$total_count = $default_count;
foreach ($folders as $f) {
    $total_count += $f['count'];
}

// Build query for the posts
if ($folder_filter === null) {
    // All saved posts (any folder)
    $stmt = $pdo->prepare("
        SELECT
            posts.id, posts.content, posts.media_path, posts.created_at, posts.edited_at,
            users.username, users.display_name, users.avatar, users.id AS author_id,
            bookmarks.created_at AS bookmarked_at, bookmarks.folder_id,
            f.name AS folder_name,
            (SELECT COUNT(*) FROM likes WHERE post_id = posts.id) AS like_count,
            (SELECT COUNT(*) FROM likes WHERE post_id = posts.id AND user_id = :me_like) AS liked_by_me,
            (SELECT COUNT(*) FROM comments WHERE post_id = posts.id) AS comment_count
        FROM bookmarks
        INNER JOIN posts ON bookmarks.post_id = posts.id
        INNER JOIN users ON posts.user_id = users.id
        LEFT JOIN bookmark_folders f ON f.id = bookmarks.folder_id
        WHERE bookmarks.user_id = :me
        ORDER BY bookmarks.created_at DESC
    ");
    $stmt->execute([':me' => $user_id, ':me_like' => $user_id]);
} elseif ($show_default) {
    // Only default folder
    $stmt = $pdo->prepare("
        SELECT
            posts.id, posts.content, posts.media_path, posts.created_at, posts.edited_at,
            users.username, users.display_name, users.avatar, users.id AS author_id,
            bookmarks.created_at AS bookmarked_at, bookmarks.folder_id,
            NULL AS folder_name,
            (SELECT COUNT(*) FROM likes WHERE post_id = posts.id) AS like_count,
            (SELECT COUNT(*) FROM likes WHERE post_id = posts.id AND user_id = :me_like) AS liked_by_me,
            (SELECT COUNT(*) FROM comments WHERE post_id = posts.id) AS comment_count
        FROM bookmarks
        INNER JOIN posts ON bookmarks.post_id = posts.id
        INNER JOIN users ON posts.user_id = users.id
        WHERE bookmarks.user_id = :me AND bookmarks.folder_id IS NULL
        ORDER BY bookmarks.created_at DESC
    ");
    $stmt->execute([':me' => $user_id, ':me_like' => $user_id]);
} else {
    // Specific folder
    $stmt = $pdo->prepare("
        SELECT
            posts.id, posts.content, posts.media_path, posts.created_at, posts.edited_at,
            users.username, users.display_name, users.avatar, users.id AS author_id,
            bookmarks.created_at AS bookmarked_at, bookmarks.folder_id,
            f.name AS folder_name,
            (SELECT COUNT(*) FROM likes WHERE post_id = posts.id) AS like_count,
            (SELECT COUNT(*) FROM likes WHERE post_id = posts.id AND user_id = :me_like) AS liked_by_me,
            (SELECT COUNT(*) FROM comments WHERE post_id = posts.id) AS comment_count
        FROM bookmarks
        INNER JOIN posts ON bookmarks.post_id = posts.id
        INNER JOIN users ON posts.user_id = users.id
        INNER JOIN bookmark_folders f ON f.id = bookmarks.folder_id
        WHERE bookmarks.user_id = :me AND bookmarks.folder_id = :folder
        ORDER BY bookmarks.created_at DESC
    ");
    $stmt->execute([':me' => $user_id, ':me_like' => $user_id, ':folder' => $folder_filter]);
}
$posts = $stmt->fetchAll();

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

$my_bookmarks = array_column($posts, 'id');

$page_title = 'Saved posts';
require 'includes/header.php';
?>

<h1>Saved posts</h1>

<?php if ($folder_message): ?>
    <div class="message"><?= htmlspecialchars($folder_message) ?></div>
<?php endif; ?>

<div class="saved-layout">

    <!-- Sidebar: folders -->
    <aside class="folders-sidebar">
        <div class="folders-header">
            <strong>Folders</strong>
            <button type="button" class="folders-add-btn" onclick="toggleNewFolder()" title="New folder">+</button>
        </div>

        <form method="POST" action="bookmark_folders.php" class="new-folder-form" id="newFolderForm" style="display:none;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <input type="text" name="name" placeholder="Folder name" maxlength="50" required>
            <div style="display:flex; gap:6px;">
                <button type="submit" class="btn btn-small">Create</button>
                <button type="button" class="btn-secondary btn-small" onclick="toggleNewFolder()">Cancel</button>
            </div>
        </form>

        <a href="saved.php" class="folder-item <?= $folder_filter === null ? 'active' : '' ?>">
            <span>All saved</span>
            <span class="folder-count"><?= $total_count ?></span>
        </a>

        <a href="saved.php?folder=0" class="folder-item <?= $show_default ? 'active' : '' ?>">
            <span>Saved</span>
            <span class="folder-count"><?= $default_count ?></span>
        </a>

        <?php foreach ($folders as $f): ?>
            <a href="saved.php?folder=<?= $f['id'] ?>" class="folder-item <?= $folder_filter === (int)$f['id'] ? 'active' : '' ?>">
                <span><?= htmlspecialchars($f['name']) ?></span>
                <span class="folder-count"><?= $f['count'] ?></span>
            </a>
            <form method="POST" action="bookmark_folders.php" class="folder-delete-form"
                  onsubmit="return confirm('Delete this folder? Posts inside will move to &quot;Saved&quot;.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="folder_id" value="<?= $f['id'] ?>">
                <input type="hidden" name="redirect" value="saved.php">
                <button type="submit" class="folder-delete-btn" title="Delete folder">×</button>
            </form>
        <?php endforeach; ?>
    </aside>

    <!-- Main content: posts -->
    <div class="saved-main">
        <?php if (empty($posts)): ?>
            <div class="empty">
                <strong>No saved posts here</strong>
                <?= $folder_filter === null
                    ? 'Tap the bookmark icon on any post to save it for later.'
                    : 'Nothing in this folder yet.' ?>
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
                            <a href="profile.php?u=<?= urlencode($post['username']) ?>" data-user-id="<?= $post['author_id'] ?>">
                                <?= htmlspecialchars($author_name) ?>
                            </a>
                        </div>
                        <?php if ($post['folder_name']): ?>
                            <span class="post-folder-badge"><?= htmlspecialchars($post['folder_name']) ?></span>
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
                        · Saved <?= htmlspecialchars(date('M j', strtotime($post['bookmarked_at']))) ?>
                    </div>

                    <div class="actions">
                        <form method="POST" action="interact.php" class="like-form" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="like">
                            <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                            <input type="hidden" name="redirect" value="saved.php<?= $folder_filter !== null ? '?folder=' . $folder_filter : '' ?>">
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
                            <input type="hidden" name="redirect" value="saved.php<?= $folder_filter !== null ? '?folder=' . $folder_filter : '' ?>">
                            <button type="submit" class="bookmark-btn bookmarked">
                                <i data-lucide="bookmark" class="bookmark-icon"></i>
                            </button>
                        </form>

                        <!-- Move to folder -->
                        <form method="POST" action="move_bookmark.php" class="folder-move-form" style="margin-left:auto;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                            <input type="hidden" name="redirect" value="saved.php<?= $folder_filter !== null ? '?folder=' . $folder_filter : '' ?>">
                            <select name="folder_id" onchange="this.form.submit()" class="folder-move-select">
                                <option value="0" <?= $post['folder_id'] === null ? 'selected' : '' ?>>Saved</option>
                                <?php foreach ($folders as $f): ?>
                                    <option value="<?= $f['id'] ?>" <?= (int)$post['folder_id'] === (int)$f['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($f['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>

                        <?php if ((int)$post['author_id'] === $user_id): ?>
                            <a href="edit_post.php?id=<?= $post['id'] ?>&from=saved.php" class="edit-link">
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
    </div>

</div>

<script>
function toggleNewFolder() {
    const form = document.getElementById('newFolderForm');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
    if (form.style.display === 'block') {
        form.querySelector('input[name="name"]').focus();
    }
}
</script>

<?php require 'includes/footer.php'; ?>