<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require_once __DIR__ . '/includes/dating.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$me = (int)$_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $me]);
if ((int)$stmt->fetchColumn() !== 1) {
    header('Location: feed.php');
    exit;
}

// Handle decision
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $verId  = (int)($_POST['verification_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $note   = trim((string)($_POST['admin_note'] ?? ''));

    if ($verId > 0 && in_array($action, ['approve', 'reject'], true)) {
        if ($note === '' || mb_strlen($note) < 5) {
            $_SESSION['flash_error'] = 'Please write a short note.';
        } elseif (mb_strlen($note) > 500) {
            $_SESSION['flash_error'] = 'Note must be 500 characters or fewer.';
        } else {
            $newStatus = $action === 'approve' ? 'approved' : 'rejected';
            try {
                $pdo->beginTransaction();
                $requestStmt = $pdo->prepare("
                    SELECT user_id, status
                    FROM dating_verification
                    WHERE id = :id
                    FOR UPDATE
                ");
                $requestStmt->execute([':id' => $verId]);
                $request = $requestStmt->fetch(PDO::FETCH_ASSOC);

                if (!$request) {
                    throw new DomainException('Verification request not found.');
                }
                if ($request['status'] !== 'pending') {
                    throw new DomainException('This verification request has already been handled.');
                }

                $targetUserId = (int)$request['user_id'];
                $updateStmt = $pdo->prepare("
                    UPDATE dating_verification
                    SET status = :s, admin_id = :a, admin_note = :n, resolved_at = NOW()
                    WHERE id = :id AND status = 'pending' AND user_id = :user_id
                ");
                $updateStmt->execute([
                    ':s' => $newStatus,
                    ':a' => $me,
                    ':n' => $note,
                    ':id' => $verId,
                    ':user_id' => $targetUserId,
                ]);
                if ($updateStmt->rowCount() !== 1) {
                    throw new RuntimeException('Verification request changed while being processed.');
                }

                if ($action === 'approve') {
                    $userStmt = $pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE');
                    $userStmt->execute([':id' => $targetUserId]);
                    if (!$userStmt->fetchColumn()) {
                        throw new RuntimeException('Verification request has no associated user.');
                    }

                    $pdo->prepare("
                        INSERT INTO dating_profiles (user_id, is_active)
                        VALUES (:user_id, 1)
                        ON DUPLICATE KEY UPDATE is_active = 1
                    ")->execute([':user_id' => $targetUserId]);
                }

                // Public moderation log
                $logAction = $action === 'approve' ? 'dating_verified' : 'dating_verification_rejected';
                $logNote   = ($action === 'approve' ? 'Approved' : 'Rejected')
                           . ' dating verification (ID ' . $verId . '): ' . $note;

                $pdo->prepare("
                    INSERT INTO moderation_log (action_type, admin_id, admin_note, created_at)
                    VALUES (:a, :admin, :note, NOW())
                ")->execute([
                    ':a'     => $logAction,
                    ':admin' => $me,
                    ':note'  => mb_substr($logNote, 0, 500),
                ]);

                $pdo->commit();
                $_SESSION['flash_success'] = 'Decision saved.';
            } catch (DomainException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['flash_error'] = $e->getMessage();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('admin_dating_verifications error: ' . $e->getMessage());
                $_SESSION['flash_error'] = 'Could not save the decision.';
            }
        }
    }
    header('Location: admin_dating_verifications.php');
    exit;
}

$pending = list_pending_dating_verifications($pdo);

$flash_error   = $_SESSION['flash_error']   ?? '';
$flash_success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

$page_title = 'Dating verifications — Admin';
require 'includes/header.php';
?>

<div class="container">
    <h1>Dating verifications</h1>
    <p style="color: var(--muted);"><?= count($pending) ?> pending</p>

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

    <?php if (empty($pending)): ?>
        <div class="empty">No pending verifications.</div>
    <?php else: ?>
        <?php foreach ($pending as $v): ?>
            <?php
            $name = $v['display_name'] ?: $v['username'];
            $primaryPhoto = get_primary_dating_photo($pdo, (int)$v['user_id']);
            ?>
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap: var(--space-3); flex-wrap: wrap;">
                    <div>
                        <a href="profile.php?u=<?= urlencode($v['username']) ?>" style="font-weight:700;">
                            <?= htmlspecialchars($name) ?>
                        </a>
                        <span style="color: var(--muted);">@<?= htmlspecialchars($v['username']) ?></span>
                        <div style="font-size:0.8rem; color:var(--muted); margin-top:4px;">
                            Submitted <?= htmlspecialchars($v['submitted_at']) ?>
                        </div>
                    </div>
                    <div>
                        <span class="post-visibility-badge" style="background:#FFF4E5; color:var(--clay);">Pending</span>
                    </div>
                </div>

                <div style="margin-top: var(--space-3); display:grid; grid-template-columns:1fr 1fr; gap: var(--space-3);">
                    <div>
                        <div style="font-size:0.8rem; color:var(--muted); font-weight:700; text-transform:uppercase;">Profile photo</div>
                        <?php if ($primaryPhoto): ?>
                            <img src="<?= htmlspecialchars($primaryPhoto) ?>" alt="" style="max-width:100%; border-radius:8px; margin-top:6px;">
                        <?php else: ?>
                            <p style="color:var(--muted); font-size:0.85rem; margin-top:6px;">No dating photo on file.</p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div style="font-size:0.8rem; color:var(--muted); font-weight:700; text-transform:uppercase;">Verification selfie (code <?= htmlspecialchars($v['code']) ?>)</div>
                        <img src="dating_verification_serve.php?id=<?= (int)$v['id'] ?>" alt="" style="max-width:100%; border-radius:8px; margin-top:6px;">
                    </div>
                </div>

                <form method="POST" style="margin-top: var(--space-4);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="verification_id" value="<?= (int)$v['id'] ?>">

                    <div class="form-field">
                        <label for="note_<?= (int)$v['id'] ?>">Decision note (required, shown publicly)</label>
                        <textarea id="note_<?= (int)$v['id'] ?>" name="admin_note" maxlength="500" rows="3" required
                                  placeholder="Explain your decision…"></textarea>
                    </div>

                    <div style="display:flex; gap: var(--space-2); margin-top: var(--space-3);">
                        <button type="submit" name="action" value="approve"
                                onclick="return confirm('Approve this verification?');">Approve</button>
                        <button type="submit" name="action" value="reject"
                                class="btn-secondary"
                                onclick="return confirm('Reject this verification?');">Reject</button>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require 'includes/footer.php'; ?>