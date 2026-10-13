<?php
require_once __DIR__ . '/includes/bootstrap.php';
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

// Confirm the user is an admin
$stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $user_id]);
$is_admin = (int)$stmt->fetchColumn() === 1;

if (!$is_admin) {
    header('Location: feed.php');
    exit;
}

$errors = [];
$success = '';

// Handle resolution
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $appeal_id = (int)($_POST['appeal_id'] ?? 0);
    $action    = $_POST['action'] ?? '';
    $reply     = trim($_POST['admin_reply'] ?? '');

    if ($appeal_id <= 0) {
        $errors[] = 'Invalid appeal.';
    } elseif (!in_array($action, ['approve', 'reject'], true)) {
        $errors[] = 'Invalid action.';
    } elseif ($reply === '') {
        $errors[] = 'Please write a short reply explaining your decision.';
    } else {
        // Load the appeal
        $stmt = $pdo->prepare("SELECT id, user_id, status FROM appeals WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $appeal_id]);
        $appeal = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$appeal) {
            $errors[] = 'Appeal not found.';
        } elseif ($appeal['status'] !== 'pending') {
            $errors[] = 'This appeal has already been resolved.';
        } else {
            $new_status = $action === 'approve' ? 'approved' : 'rejected';

            $pdo->beginTransaction();
            try {
                // 1. Update appeal
                $stmt = $pdo->prepare("
                    UPDATE appeals
                    SET status = :status,
                        admin_id = :admin,
                        admin_reply = :reply,
                        resolved_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':status' => $new_status,
                    ':admin'  => $user_id,
                    ':reply'  => $reply,
                    ':id'     => $appeal_id,
                ]);

                // 2. If approved, lift the ban
                if ($action === 'approve') {
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET is_banned = 0,
                            ban_reason = NULL,
                            banned_at = NULL,
                            banned_by = NULL
                        WHERE id = :uid
                    ");
                    $stmt->execute([':uid' => (int)$appeal['user_id']]);
                }

                // 3. Log to public moderation_log
         // 3. Log to moderation_log
$logAction = $action === 'approve' ? 'appeal_approved' : 'appeal_rejected';

// Load the target user for context (so the log entry has a readable note)
$stmt = $pdo->prepare("SELECT username FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => (int)$appeal['user_id']]);
$target_username = (string)$stmt->fetchColumn();

$logNote = 'Appeal from @' . $target_username . ': ' . $reply;

$stmt = $pdo->prepare("
    INSERT INTO moderation_log
        (action_type, admin_id, admin_note, created_at)
    VALUES
        (:action, :admin, :note, NOW())
");
$stmt->execute([
    ':action' => $logAction,
    ':admin'  => $user_id,
    ':note'   => mb_substr($logNote, 0, 500),   // column is varchar(500)
]);

                $pdo->commit();
                $success = 'Appeal ' . $new_status . '.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Could not save: ' . $e->getMessage();
            }
        }
    }
}

// Load all appeals
$stmt = $pdo->prepare("
    SELECT a.id, a.user_id, a.ban_reason, a.appeal_text, a.status,
           a.admin_reply, a.created_at, a.resolved_at,
           u.username, u.display_name
    FROM appeals a
    INNER JOIN users u ON u.id = a.user_id
    ORDER BY
        CASE a.status WHEN 'pending' THEN 0 ELSE 1 END,
        a.created_at DESC
    LIMIT 200
");
$stmt->execute();
$appeals = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Appeals — Admin';
require 'includes/header.php';
?>

<div class="container">
    <h1>Appeals</h1>
    <p style="color: var(--muted);"><?= count($appeals) ?> total</p>

    <?php if (!empty($errors)): ?>
        <div class="message" style="border-left-color: var(--danger); background:#FDECEA;">
            <?php foreach ($errors as $e): ?>
                <div><?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="message" style="border-left-color: var(--baobab-green); background:#E8F5E9;">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($appeals)): ?>
        <div class="empty">No appeals yet.</div>
    <?php else: ?>
        <?php foreach ($appeals as $a): ?>
            <?php $name = $a['display_name'] ?: $a['username']; ?>
            <div class="card" style="border-left:4px solid <?= $a['status'] === 'pending' ? 'var(--terracotta)' : ($a['status'] === 'approved' ? 'var(--baobab-green)' : 'var(--danger)') ?>;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap: var(--space-3); flex-wrap:wrap;">
                    <div>
                        <a href="profile.php?u=<?= urlencode($a['username']) ?>" style="font-weight:700; font-size:1.05rem; color:var(--deep-brown);">
                            <?= htmlspecialchars($name) ?>
                        </a>
                        <span style="color:var(--muted);">@<?= htmlspecialchars($a['username']) ?></span>
                    </div>
                    <div>
                        <?php if ($a['status'] === 'pending'): ?>
                            <span class="post-visibility-badge" style="background:#FFF4E5; color:var(--clay);">Pending</span>
                        <?php elseif ($a['status'] === 'approved'): ?>
                            <span class="post-visibility-badge" style="background:#E8F5E9; color:var(--baobab-green);">Approved</span>
                        <?php else: ?>
                            <span class="post-visibility-badge" style="background:#FDECEA; color:var(--danger);">Rejected</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="font-size:0.8rem; color:var(--muted); margin-top: var(--space-2);">
                    Submitted <?= htmlspecialchars($a['created_at']) ?>
                </div>

                <div style="margin-top: var(--space-3);">
                    <div style="font-size:0.8rem; color:var(--muted); font-weight:700; text-transform:uppercase;">Ban reason</div>
                    <p style="margin-top:4px;"><?= htmlspecialchars($a['ban_reason'] ?: 'Not specified') ?></p>
                </div>

                <div style="margin-top: var(--space-3);">
                    <div style="font-size:0.8rem; color:var(--muted); font-weight:700; text-transform:uppercase;">Their appeal</div>
                    <div style="margin-top:4px; padding: var(--space-3); background:var(--sand); border-radius:var(--radius-md); white-space:pre-wrap;">
                        <?= htmlspecialchars($a['appeal_text']) ?>
                    </div>
                </div>

                <?php if ($a['status'] === 'pending'): ?>
                    <form method="POST" style="margin-top: var(--space-4);">
                        <?= csrf_field() ?>
                        <input type="hidden" name="appeal_id" value="<?= (int)$a['id'] ?>">

                        <div class="form-field">
                            <label for="reply_<?= (int)$a['id'] ?>">Your reply (required, logged publicly)</label>
                            <textarea id="reply_<?= (int)$a['id'] ?>" name="admin_reply" maxlength="1000" rows="3" required
                                      placeholder="Explain your decision…"></textarea>
                        </div>

                        <div style="display:flex; gap: var(--space-2); margin-top: var(--space-3);">
                            <button type="submit" name="action" value="approve"
                                    onclick="return confirm('Approve this appeal and lift the ban?');">
                                Approve & lift ban
                            </button>
                            <button type="submit" name="action" value="reject"
                                    class="btn-secondary"
                                    onclick="return confirm('Reject this appeal?');">
                                Reject
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div style="margin-top: var(--space-4);">
                        <div style="font-size:0.8rem; color:var(--muted); font-weight:700; text-transform:uppercase;">Admin reply</div>
                        <p style="margin-top:4px; white-space:pre-wrap;"><?= htmlspecialchars($a['admin_reply'] ?? '') ?></p>
                        <?php if (!empty($a['resolved_at'])): ?>
                            <div style="font-size:0.75rem; color:var(--muted); margin-top:4px;">
                                Resolved <?= htmlspecialchars($a['resolved_at']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require 'includes/footer.php'; ?>