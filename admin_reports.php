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

$stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$is_admin = (bool)$stmt->fetchColumn();

if (!$is_admin) {
    header('Location: feed.php');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $report_id = (int)($_POST['report_id'] ?? 0);
    $action = $_POST['admin_action'] ?? '';

    if ($report_id > 0 && in_array($action, ['dismiss', 'remove'], true)) {
        // Fetch the report including reason (needed for the log)
        $stmt = $pdo->prepare("SELECT post_id, comment_id, reason FROM reports WHERE id = :id");
        $stmt->execute([':id' => $report_id]);
        $report = $stmt->fetch();

        if ($report) {
            $report_reason = $report['reason'] ?? null;

            // ---- Remove action ----
            if ($action === 'remove' && !empty($report['post_id'])) {
                // Fetch post content BEFORE deleting (for the log excerpt)
                $stmt = $pdo->prepare("SELECT content FROM posts WHERE id = :id");
                $stmt->execute([':id' => $report['post_id']]);
                $post_content = $stmt->fetchColumn();

                // Only include excerpt for safe categories
                $safe_categories = ['spam', 'misinformation', 'harassment', 'other'];
                $excerpt = ($post_content && in_array($report_reason, $safe_categories, true))
                    ? mb_substr($post_content, 0, 200)
                    : null;

                // Delete the post (cascades to likes, comments, notifications)
                $stmt = $pdo->prepare("DELETE FROM posts WHERE id = :id");
                $stmt->execute([':id' => $report['post_id']]);

                // Log the removal publicly
                $stmt = $pdo->prepare("
                    INSERT INTO moderation_log
                        (action_type, post_excerpt, policy_category, admin_id)
                    VALUES ('post_removed', :excerpt, :category, :admin)
                ");
                $stmt->execute([
                    ':excerpt' => $excerpt,
                    ':category' => $report_reason,
                    ':admin' => $_SESSION['user_id'],
                ]);
            }

            // ---- Dismiss action ----
            if ($action === 'dismiss') {
                // Log the dismissal publicly
                $stmt = $pdo->prepare("
                    INSERT INTO moderation_log
                        (action_type, policy_category, admin_id)
                    VALUES ('report_dismissed', :category, :admin)
                ");
                $stmt->execute([
                    ':category' => $report_reason,
                    ':admin' => $_SESSION['user_id'],
                ]);
            }

            // ---- Update report status ----
            $new_status = $action === 'remove' ? 'actioned' : 'dismissed';
            $stmt = $pdo->prepare("
                UPDATE reports
                SET status = :status, reviewed_by = :admin, reviewed_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':status' => $new_status,
                ':admin' => $_SESSION['user_id'],
                ':id' => $report_id,
            ]);

            $message = $action === 'remove' ? 'Content removed.' : 'Report dismissed.';
        }
    }
}

// Fetch pending reports
$stmt = $pdo->prepare("
    SELECT
        r.id, r.reason, r.details, r.created_at,
        reporter.username AS reporter_username,
        p.id AS post_id, p.content AS post_content, p.media_path AS post_media,
        p.created_at AS post_created,
        author.username AS author_username, author.id AS author_id
    FROM reports r
    INNER JOIN users reporter ON r.reporter_id = reporter.id
    LEFT JOIN posts p ON r.post_id = p.id
    LEFT JOIN users author ON p.user_id = author.id
    WHERE r.status = 'pending'
    ORDER BY r.created_at ASC
");
$stmt->execute();
$reports = $stmt->fetchAll();

$page_title = __('admin_title');
require 'includes/header.php';
?>

<h1><?= __('admin_title') ?> (<?= count($reports) ?>)</h1>

<?php if ($message): ?>
    <div class="message"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if (empty($reports)): ?>
    <div class="empty"><?= __('admin_no_reports') ?></div>
<?php else: ?>
    <?php foreach ($reports as $r): ?>
        <div class="card" style="border-left: 4px solid var(--terracotta);">
            <div class="meta">
                <?= htmlspecialchars($r['reporter_username']) ?> —
                <?= htmlspecialchars($r['created_at']) ?>
            </div>

            <h3 style="margin: 10px 0 6px; color: var(--terracotta);">
                <?= htmlspecialchars(ucfirst($r['reason'])) ?>
            </h3>

            <?php if ($r['details']): ?>
                <p style="font-style: italic; color: var(--muted);">
                    "<?= htmlspecialchars($r['details']) ?>"
                </p>
            <?php endif; ?>

            <hr style="border: none; border-top: 1px solid var(--border); margin: 12px 0;">

            <div class="content" style="margin: 8px 0;">
                <?= htmlspecialchars($r['post_content']) ?>
            </div>

            <?php if ($r['post_media']): ?>
                <img class="media" src="<?= htmlspecialchars($r['post_media']) ?>" alt="">
            <?php endif; ?>

            <div style="display:flex; gap:10px; margin-top:12px; flex-wrap:wrap;">
                <a href="profile.php?u=<?= urlencode($r['author_username']) ?>" class="btn-secondary">
                    <?= __('nav_profile') ?>
                </a>
                <form method="POST" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                    <input type="hidden" name="admin_action" value="dismiss">
                    <button type="submit" class="btn-secondary"><?= __('admin_dismiss') ?></button>
                </form>
                <form method="POST" style="display:inline;"
                      onsubmit="return confirm('Remove this post permanently?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                    <input type="hidden" name="admin_action" value="remove">
                    <button type="submit" class="btn-danger"><?= __('admin_remove') ?></button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>