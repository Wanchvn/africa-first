<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require_once __DIR__ . '/includes/verification.php';
require_once __DIR__ . '/includes/badge.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$admin_id = (int)$_SESSION['user_id'];

// ---- Admin gate (must run BEFORE the cleanup) ----
$stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $admin_id]);
if ((int)$stmt->fetchColumn() !== 1) {
    header('Location: feed.php');
    exit;
}

// ---- Lazy retention cleanup (admin-only page) ----
$cleaned = cleanup_expired_verification_docs($pdo);
if ($cleaned > 0) {
    error_log("verification cleanup: removed docs for {$cleaned} application(s)");
}

// ---- Load all applications ----
$stmt = $pdo->prepare("
    SELECT a.*, u.username, u.display_name, u.avatar, u.verification_type
    FROM verification_applications a
    INNER JOIN users u ON u.id = a.user_id
    ORDER BY
        CASE a.status WHEN 'pending' THEN 0 ELSE 1 END,
        a.created_at DESC
    LIMIT 200
");
$stmt->execute();
$apps = $stmt->fetchAll(PDO::FETCH_ASSOC);

$flash_error   = $_SESSION['flash_error']   ?? '';
$flash_success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

$page_title = 'Verification requests';
require 'includes/header.php';
?>

<div class="container">
    <h1>Verification requests</h1>
    <p style="color: var(--muted);"><?= count($apps) ?> total</p>

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

    <?php if (empty($apps)): ?>
        <div class="empty">No verification requests yet.</div>
    <?php else: ?>
        <?php foreach ($apps as $a): ?>
            <?php
            $name   = $a['display_name'] ?: $a['username'];
            $accent = $a['status'] === 'pending'  ? 'var(--terracotta)'
                    : ($a['status'] === 'approved' ? 'var(--baobab-green)' : 'var(--danger)');

            $has_front  = !empty($a['id_front_path']);
            $has_back   = !empty($a['id_back_path']);
            $has_selfie = !empty($a['selfie_path']);
            $has_any    = $has_front || $has_back || $has_selfie;
            ?>
            <div class="card" style="border-left: 4px solid <?= $accent ?>;">
                <div style="display:flex; justify-content:space-between; gap: var(--space-3); flex-wrap:wrap;">
                    <div>
                        <a href="profile.php?u=<?= urlencode($a['username']) ?>" style="font-weight:700; font-size:1.05rem;">
                            <?= htmlspecialchars($name) ?>
                        </a>
                        <span style="color:var(--muted);">@<?= htmlspecialchars($a['username']) ?></span>
                        <?= verification_badge($a['verification_type'] ?? 'none', 13) ?>
                    </div>
                    <div>
                        <span class="post-visibility-badge"
                              style="background:<?= $accent ?>; color:#fff; text-transform:capitalize;">
                            <?= htmlspecialchars($a['status']) ?>
                        </span>
                    </div>
                </div>

                <div style="font-size:0.8rem; color:var(--muted); margin-top: var(--space-2);">
                    Submitted <?= htmlspecialchars($a['created_at']) ?>
                </div>

                <div class="verify-meta-grid" style="margin-top: var(--space-3);">
                    <div><strong>Category</strong></div>
                    <div><?= htmlspecialchars(ucfirst(str_replace('_',' ',$a['category']))) ?></div>

                    <div><strong>Legal name</strong></div>
                    <div><?= htmlspecialchars($a['full_legal_name']) ?></div>

                    <div><strong>Country</strong></div>
                    <div><?= htmlspecialchars($a['country']) ?></div>

                    <div><strong>ID type</strong></div>
                    <div><?= htmlspecialchars(ucfirst(str_replace('_',' ',$a['id_type']))) ?></div>

                    <div><strong>ID number</strong></div>
                    <div><?= htmlspecialchars(mask_id_number($a['id_number'])) ?></div>
                </div>

                <div style="margin-top: var(--space-3);">
                    <div style="font-size:0.8rem; color:var(--muted); font-weight:700; text-transform:uppercase;">Reason</div>
                    <p style="margin-top:4px; white-space:pre-wrap;"><?= htmlspecialchars($a['reason']) ?></p>
                </div>

                <?php if (!empty($a['public_url'])): ?>
                    <div style="margin-top: var(--space-2);">
                        <a href="<?= htmlspecialchars($a['public_url']) ?>" target="_blank" rel="noopener">
                            <?= htmlspecialchars($a['public_url']) ?>
                        </a>
                    </div>
                <?php endif; ?>

                <?php if ($has_any): ?>
                    <?php if ($a['status'] !== 'pending' && !empty($a['docs_delete_after'])): ?>
                        <div style="font-size: 0.8rem; color: var(--muted); margin-top: var(--space-2);">
                            <i data-lucide="clock" style="width:12px;height:12px;vertical-align:middle;"></i>
                            Documents will be permanently deleted on <?= htmlspecialchars($a['docs_delete_after']) ?>
                        </div>
                    <?php endif; ?>

                    <div class="verify-docs-row" style="margin-top: var(--space-3);">
                        <?php if ($has_front): ?>
                            <a href="verify_doc.php?id=<?= (int)$a['id'] ?>&slot=front" target="_blank" rel="noopener" title="ID front">
                                <img class="verify-thumb" src="verify_doc.php?id=<?= (int)$a['id'] ?>&slot=front" alt="ID front">
                            </a>
                        <?php endif; ?>
                        <?php if ($has_back): ?>
                            <a href="verify_doc.php?id=<?= (int)$a['id'] ?>&slot=back" target="_blank" rel="noopener" title="ID back">
                                <img class="verify-thumb" src="verify_doc.php?id=<?= (int)$a['id'] ?>&slot=back" alt="ID back">
                            </a>
                        <?php endif; ?>
                        <?php if ($has_selfie): ?>
                            <a href="verify_doc.php?id=<?= (int)$a['id'] ?>&slot=selfie" target="_blank" rel="noopener" title="Selfie with ID">
                                <img class="verify-thumb" src="verify_doc.php?id=<?= (int)$a['id'] ?>&slot=selfie" alt="Selfie with ID">
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="verify-docs-row" style="margin-top: var(--space-3);">
                        <p style="color: var(--muted); font-size: 0.85rem; margin: 0;">
                            <i data-lucide="trash-2" style="width:14px;height:14px;vertical-align:middle;"></i>
                            Documents were permanently deleted 30 days after resolution.
                        </p>
                    </div>
                <?php endif; ?>

                <?php if ($a['status'] === 'pending'): ?>
                    <form method="POST" action="admin_verify_decision.php" style="margin-top: var(--space-4);">
                        <?= csrf_field() ?>
                        <input type="hidden" name="application_id" value="<?= (int)$a['id'] ?>">

                        <div class="form-field">
                            <label for="note_<?= (int)$a['id'] ?>">Decision note (required, shown publicly)</label>
                            <textarea id="note_<?= (int)$a['id'] ?>" name="admin_note" maxlength="500" rows="3" required
                                      placeholder="Explain your decision…"></textarea>
                        </div>

                        <div style="display:flex; gap: var(--space-2); margin-top: var(--space-3);">
                            <button type="submit" name="action" value="approve"
                                    onclick="return confirm('Approve this application and grant the badge?');">
                                Approve &amp; grant badge
                            </button>
                            <button type="submit" name="action" value="reject"
                                    class="btn-secondary"
                                    onclick="return confirm('Reject this application?');">
                                Reject
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div style="margin-top: var(--space-4);">
                        <div style="font-size:0.8rem; color:var(--muted); font-weight:700; text-transform:uppercase;">Admin note</div>
                        <p style="margin-top:4px; white-space:pre-wrap;"><?= htmlspecialchars($a['admin_note'] ?? '') ?></p>
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