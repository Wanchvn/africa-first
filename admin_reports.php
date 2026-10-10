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

$admin_id = (int)$_SESSION['user_id'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $report_id = (int)($_POST['report_id'] ?? 0);
    $action = $_POST['admin_action'] ?? '';

    if ($report_id > 0 && in_array($action, ['dismiss', 'remove'], true)) {
        $stmt = $pdo->prepare(
            "SELECT post_id, comment_id, conversation_id, message_id, reason
             FROM reports WHERE id = :id"
        );
        $stmt->execute([':id' => $report_id]);
        $report = $stmt->fetch();

        if ($report) {
            $report_reason = $report['reason'] ?? null;
            $safe_categories = ['spam', 'misinformation', 'harassment', 'other'];

            // ---- Remove: Post ----
            if ($action === 'remove' && !empty($report['post_id'])) {
                $stmt = $pdo->prepare("SELECT content FROM posts WHERE id = :id");
                $stmt->execute([':id' => $report['post_id']]);
                $post_content = $stmt->fetchColumn();

                $excerpt = ($post_content && in_array($report_reason, $safe_categories, true))
                    ? mb_substr($post_content, 0, 200)
                    : null;

                $stmt = $pdo->prepare("DELETE FROM posts WHERE id = :id");
                $stmt->execute([':id' => $report['post_id']]);

                $stmt = $pdo->prepare("
                    INSERT INTO moderation_log
                        (action_type, post_excerpt, policy_category, admin_id)
                    VALUES ('post_removed', :excerpt, :category, :admin)
                ");
                $stmt->execute([
                    ':excerpt'  => $excerpt,
                    ':category' => $report_reason,
                    ':admin'    => $admin_id,
                ]);
            }

            // ---- Remove: DM message ----
            if ($action === 'remove' && !empty($report['message_id'])) {
                $stmt = $pdo->prepare("SELECT body FROM messages WHERE id = :id");
                $stmt->execute([':id' => $report['message_id']]);
                $msg_body = $stmt->fetchColumn();

                $excerpt = ($msg_body && in_array($report_reason, $safe_categories, true))
                    ? mb_substr($msg_body, 0, 200)
                    : null;

                $stmt = $pdo->prepare("DELETE FROM messages WHERE id = :id");
                $stmt->execute([':id' => $report['message_id']]);

                // ✅ Correct column names + correct action type + correct variables
                $stmt = $pdo->prepare("
                    INSERT INTO moderation_log
                        (action_type, post_excerpt, policy_category, admin_id)
                    VALUES ('message_removed', :excerpt, :category, :admin)
                ");
                $stmt->execute([
                    ':excerpt'  => $excerpt,
                    ':category' => $report_reason,
                    ':admin'    => $admin_id,
                ]);
            }

            // ---- Dismiss ----
            if ($action === 'dismiss') {
                $stmt = $pdo->prepare("
                    INSERT INTO moderation_log
                        (action_type, policy_category, admin_id)
                    VALUES ('report_dismissed', :category, :admin)
                ");
                $stmt->execute([
                    ':category' => $report_reason,
                    ':admin'    => $admin_id,
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
                ':admin'  => $admin_id,
                ':id'     => $report_id,
            ]);

            $message = $action === 'remove' ? 'Content removed.' : 'Report dismissed.';
        }
    }
}

// ---------- Post reports ----------
$stmt = $pdo->prepare("
    SELECT
        r.id, r.reason, r.details, r.created_at,
        reporter.username AS reporter_username,
        reporter.display_name AS reporter_display_name,
        p.id AS post_id, p.content AS post_content, p.media_path AS post_media,
        p.created_at AS post_created,
        author.username AS author_username,
        author.display_name AS author_display_name,
        author.id AS author_id
    FROM reports r
    INNER JOIN users reporter ON r.reporter_id = reporter.id
    LEFT JOIN posts p ON r.post_id = p.id
    LEFT JOIN users author ON p.user_id = author.id
    WHERE r.status = 'pending'
      AND r.conversation_id IS NULL
    ORDER BY r.created_at ASC
");
$stmt->execute();
$reports = $stmt->fetchAll();

// ---------- DM reports ----------
$stmt = $pdo->prepare("
    SELECT
        r.id, r.reason, r.details, r.created_at,
        r.conversation_id, r.message_id,
        reporter.username AS reporter_username,
        reporter.display_name AS reporter_display_name,
        m.body AS message_body,
        m.created_at AS message_created,
        m.deleted_at AS message_deleted_at,
        m.sender_id AS message_sender_id,
        sender.username AS sender_username,
        sender.display_name AS sender_display_name,
        sender.id AS sender_id
    FROM reports r
    INNER JOIN users reporter ON r.reporter_id = reporter.id
    LEFT JOIN messages m ON r.message_id = m.id
    LEFT JOIN users sender ON m.sender_id = sender.id
    WHERE r.status = 'pending'
      AND r.conversation_id IS NOT NULL
    ORDER BY r.created_at ASC
");
$stmt->execute();
$dm_reports = $stmt->fetchAll();

$page_title = __('admin_title');
require 'includes/header.php';
?>

<h1><?= __('admin_title') ?>
    (<?= count($reports) + count($dm_reports) ?>)</h1>

<?php if ($message): ?>
    <div class="message"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if (empty($reports) && empty($dm_reports)): ?>
    <div class="empty"><?= __('admin_no_reports') ?></div>
<?php else: ?>

    <?php /* ========== DM reports first ========== */ ?>
    <?php if (!empty($dm_reports)): ?>
        <h2 style="margin-top: var(--space-5);">Direct messages (<?= count($dm_reports) ?>)</h2>

        <?php foreach ($dm_reports as $r): ?>
            <?php
            $reporter_name = $r['reporter_display_name'] ?: $r['reporter_username'];
            $sender_name   = $r['sender_display_name']   ?: $r['sender_username'];
            ?>
            <div class="card" style="border-left: 4px solid var(--terracotta);">
                <div class="meta">
                    <?= htmlspecialchars($reporter_name) ?> —
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

                <?php if ($r['message_id'] && !empty($r['message_deleted_at'])): ?>
                    <!-- Message was deleted by its sender after the report -->
                    <div class="meta" style="margin-bottom: 6px;">
                        Message from <strong><?= htmlspecialchars($sender_name) ?></strong>
                    </div>
                    <div class="report-preview" style="font-style:italic; color: var(--muted);">
                        <?= __('message_deleted') ?>
                    </div>
                <?php elseif ($r['message_id'] && $r['message_body']): ?>
                    <div class="meta" style="margin-bottom: 6px;">
                        Message from <strong><?= htmlspecialchars($sender_name) ?></strong>
                        <?php if ($r['message_created']): ?>
                            · <?= htmlspecialchars($r['message_created']) ?>
                        <?php endif; ?>
                    </div>
                    <div class="report-preview">
                        "<?= htmlspecialchars($r['message_body']) ?>"
                    </div>
                <?php else: ?>
                    <div class="meta" style="margin-bottom: 6px;">
                        <em>Whole-conversation report (no specific message).</em>
                    </div>
                <?php endif; ?>

                <div style="display:flex; gap:10px; margin-top:12px; flex-wrap:wrap;">
                    <?php if ($r['sender_username']): ?>
                        <a href="profile.php?u=<?= urlencode($r['sender_username']) ?>" class="btn-secondary">
                            <?= __('nav_profile') ?>
                        </a>
                    <?php endif; ?>
                    <a href="conversation.php?id=<?= (int)$r['conversation_id'] ?>" class="btn-secondary">
                        Open conversation
                    </a>

                    <form method="POST" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="admin_action" value="dismiss">
                        <button type="submit" class="btn-secondary"><?= __('admin_dismiss') ?></button>
                    </form>

                    <?php if ($r['message_id'] && empty($r['message_deleted_at'])): ?>
                        <form method="POST" style="display:inline;"
                              onsubmit="return confirm('Remove this message permanently?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                            <input type="hidden" name="admin_action" value="remove">
                            <button type="submit" class="btn-danger"><?= __('admin_remove') ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php /* ========== Post reports ========== */ ?>
    <?php if (!empty($reports)): ?>
        <h2 style="margin-top: var(--space-5);">Posts (<?= count($reports) ?>)</h2>

        <?php foreach ($reports as $r): ?>
            <?php
            $reporter_name = $r['reporter_display_name'] ?: $r['reporter_username'];
            $author_name   = $r['author_display_name'] ?: $r['author_username'];
            ?>
            <div class="card" style="border-left: 4px solid var(--terracotta);">
                <div class="meta">
                    <?= htmlspecialchars($reporter_name) ?> —
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

                <div class="meta" style="margin-bottom: 6px;">
                    Post by <strong><?= htmlspecialchars($author_name) ?></strong>
                </div>

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

<?php endif; ?>

<?php require 'includes/footer.php'; ?>