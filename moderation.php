<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT 
        ml.id, ml.action_type, ml.post_excerpt, ml.policy_category,
        ml.admin_note, ml.created_at,
        u.username AS admin_username
    FROM moderation_log ml
    LEFT JOIN users u ON ml.admin_id = u.id
    ORDER BY ml.created_at DESC
    LIMIT 200
");
$stmt->execute();
$entries = $stmt->fetchAll();

// Counts
$stmt = $pdo->query("SELECT COUNT(*) FROM moderation_log");
$total = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM moderation_log WHERE action_type = 'post_removed'");
$total_removed = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM moderation_log WHERE action_type = 'report_dismissed'");
$total_dismissed = (int)$stmt->fetchColumn();

$page_title = 'Moderation Log';
require 'includes/header.php';
?>

<h1><?= __('mod_title') ?? 'Moderation Log' ?></h1>

<div class="card" style="background: #FFF9F5; border-left: 4px solid var(--baobab-green);">
    <h2 style="margin-top:0; color: var(--baobab-green);">Every action, in public</h2>
    <p>
        Most platforms moderate in secret. Qarota doesn't.
        Every post removal and every dismissed report is published here permanently.
        Users can see exactly how the platform is run.
    </p>
    <p style="margin-top:10px;">
        <strong><?= $total ?></strong> total actions ·
        <strong><?= $total_removed ?></strong> posts removed ·
        <strong><?= $total_dismissed ?></strong> reports dismissed
    </p>
</div>

<?php if (empty($entries)): ?>
    <div class="empty">
        <strong>No moderation actions yet.</strong>
        <br><span style="font-size:0.9rem;">When admins remove posts or dismiss reports, they'll appear here.</span>
    </div>
<?php else: ?>
    <?php foreach ($entries as $e): ?>
        <div class="card">
            <div class="meta" style="margin-bottom:8px;">
                <?= htmlspecialchars($e['created_at']) ?>
                · decided by
                <strong><?= htmlspecialchars($e['admin_username'] ?? 'former admin') ?></strong>
            </div>

            <?php if ($e['action_type'] === 'post_removed'): ?>
                <div style="color:#c0392b; font-weight:600; margin-bottom:6px;">
                    Post removed
                    <?php if ($e['policy_category']): ?>
                        — <?= htmlspecialchars(ucfirst($e['policy_category'])) ?>
                    <?php endif; ?>
                </div>
                <p style="font-size:0.9rem; color:var(--muted);">
                    An admin reviewed a report and confirmed a violation of Qarota's policies.
                    The content has been permanently removed.
                </p>
                <?php if ($e['post_excerpt']): ?>
                    <div style="background:var(--sand); padding:10px; border-radius:8px; font-style:italic; color:var(--muted); font-size:0.9rem;">
                        "<?= htmlspecialchars($e['post_excerpt']) ?><?= mb_strlen($e['post_excerpt']) >= 200 ? '…' : '' ?>"
                    </div>
                <?php endif; ?>
            <?php elseif ($e['action_type'] === 'report_dismissed'): ?>
                <div style="color:var(--muted); font-weight:600; margin-bottom:6px;">
                    Report dismissed
                    <?php if ($e['policy_category']): ?>
                        — <?= htmlspecialchars(ucfirst($e['policy_category'])) ?>
                    <?php endif; ?>
                </div>
                <p style="font-size:0.9rem; color:var(--muted);">
                    An admin reviewed a report and found no violation of Qarota's policies.
                </p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>