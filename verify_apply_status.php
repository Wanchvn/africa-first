<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require_once __DIR__ . '/includes/verification.php';
require_once __DIR__ . '/includes/badge.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$app     = verification_application_status($pdo, $user_id);

$flash_error   = $_SESSION['flash_error']   ?? '';
$flash_success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

$page_title = 'Verification';
require 'includes/header.php';
?>

<div class="container" style="max-width: 640px;">
    <h1>Verification</h1>

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

    <?php if (!$app): ?>
        <div class="card">
            <h2 style="margin-top:0;">No application yet</h2>
            <p style="color:var(--muted);">
                You haven't submitted a verification request. You can apply if you're a
                journalist, public figure, organization, or business.
            </p>
            <a href="verify_apply.php" class="btn">Apply for verification</a>
        </div>
    <?php else: ?>
        <?php
        $accent = match ($app['status']) {
            'pending'  => 'var(--terracotta)',
            'approved' => 'var(--baobab-green)',
            'rejected' => 'var(--danger)',
            default    => 'var(--border)',
        };
        ?>
        <div class="card verify-status-card" style="border-left: 4px solid <?= $accent ?>;">
            <h2 style="margin-top:0; text-transform: capitalize;">
                <?= htmlspecialchars($app['status']) ?>
            </h2>

            <div class="verify-meta-grid">
                <div><strong>Category</strong></div>
                <div><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $app['category']))) ?></div>

                <div><strong>Legal name</strong></div>
                <div><?= htmlspecialchars($app['full_legal_name']) ?></div>

                <div><strong>Country</strong></div>
                <div><?= htmlspecialchars($app['country']) ?></div>

                <div><strong>Submitted</strong></div>
                <div><?= htmlspecialchars($app['created_at']) ?></div>

                <?php if (!empty($app['resolved_at'])): ?>
                    <div><strong>Resolved</strong></div>
                    <div><?= htmlspecialchars($app['resolved_at']) ?></div>
                <?php endif; ?>
            </div>

            <?php if ($app['status'] === 'pending'): ?>
                <p style="margin-top: var(--space-4); color:var(--muted);">
                    Your application is being reviewed. You'll get a notification when there's a decision.
                </p>
            <?php elseif ($app['status'] === 'approved'): ?>
                <p style="margin-top: var(--space-4);">
                    <strong>Congratulations.</strong> Your account is now verified.
                </p>
                <a href="profile.php" class="btn">View your profile</a>
            <?php elseif ($app['status'] === 'rejected'): ?>
                <p style="margin-top: var(--space-4); color:var(--muted);">
                    Unfortunately your application was not approved.
                </p>
                <?php if (!empty($app['admin_note'])): ?>
                    <div style="background:var(--sand); padding: var(--space-3); border-radius: var(--radius-md); margin-top: var(--space-3);">
                        <div style="font-size:0.8rem; color:var(--muted); font-weight:700; text-transform:uppercase;">Admin note</div>
                        <p style="margin-top:4px; white-space:pre-wrap;"><?= htmlspecialchars($app['admin_note']) ?></p>
                    </div>
                <?php endif; ?>
                <?php
                // Allow re-application 30 days after rejection
                $can_reapply = $app['resolved_at'] && strtotime($app['resolved_at']) < strtotime('-30 days');
                ?>
                <?php if ($can_reapply): ?>
                    <a href="verify_apply.php" class="btn-secondary" style="margin-top: var(--space-3);">Submit a new application</a>
                <?php else: ?>
                    <p style="color:var(--muted); font-size: 0.85rem; margin-top: var(--space-3);">
                        You can submit a new application 30 days after this decision.
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php require 'includes/footer.php'; ?>