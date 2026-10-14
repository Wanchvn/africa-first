<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Load all reports the user has filed, with context
$stmt = $pdo->prepare("
    SELECT
        r.id, r.reason, r.details, r.status, r.created_at,
        r.resolved_at, r.resolution_note,
        r.post_id, r.message_id, r.conversation_id,

        -- Post context (if reporting a post)
        p.content AS post_content,
        p.media_type AS post_media_type,
        pu.username AS post_author_username,
        pu.display_name AS post_author_display,
        pu.id AS post_author_id,

        -- Message context (if reporting a DM)
        m.message_type AS msg_type,
        m.body AS msg_body,

        -- Admin who resolved it
        au.username AS admin_username,
        au.display_name AS admin_display
    FROM reports r
    LEFT JOIN posts p      ON p.id = r.post_id
    LEFT JOIN users pu     ON pu.id = p.user_id
    LEFT JOIN messages m   ON m.id = r.message_id
    LEFT JOIN users au     ON au.id = r.resolved_by
    WHERE r.reporter_id = :me
    ORDER BY r.created_at DESC
    LIMIT 200
");
$stmt->execute([':me' => $user_id]);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Decrypt DM bodies for preview
require_once __DIR__ . '/includes/crypto.php';
foreach ($reports as &$r) {
    if (!empty($r['msg_body'])) {
        $r['msg_body'] = crypto_decrypt($r['msg_body']);
    }
}
unset($r);

$page_title = 'My reports';
require 'includes/header.php';
?>

<div class="container" style="max-width: 720px;">
    <h1>My reports</h1>
    <p style="color: var(--muted);">
        Every report you've filed on Qarota. When an admin resolves it,
        you'll see the outcome here.
    </p>

    <?php if (empty($reports)): ?>
        <div class="empty">
            <strong>You haven't reported anything yet.</strong>
            <br><span style="font-size:0.9rem;">
                If you see content that violates our <a href="privacy_policy.php">policies</a>,
                use the <em>Report</em> link on the post or message.
            </span>
        </div>
    <?php else: ?>
        <?php foreach ($reports as $r): ?>
            <?php
            $status = $r['status'];
            $accent = match ($status) {
                'pending'   => 'var(--terracotta)',
                'actioned'  => 'var(--baobab-green)',
                'dismissed' => 'var(--muted)',
                'resolved'  => 'var(--baobab-green)',
                default     => 'var(--border)',
            };
            $status_label = match ($status) {
                'pending'   => 'Pending review',
                'actioned'  => 'Action taken',
                'dismissed' => 'Dismissed',
                'resolved'  => 'Resolved',
                default     => ucfirst($status),
            };
            ?>
            <div class="card" style="border-left: 4px solid <?= $accent ?>;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap: var(--space-3); flex-wrap:wrap;">
                    <div>
                        <div style="font-weight: 600; text-transform: capitalize; color: var(--deep-brown);">
                            <?= htmlspecialchars(str_replace('_', ' ', $r['reason'])) ?>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--muted); margin-top: 4px;">
                            Reported <?= htmlspecialchars($r['created_at']) ?>
                        </div>
                    </div>
                    <div>
                        <span class="post-visibility-badge" style="background:<?= $accent ?>; color:#fff;">
                            <?= htmlspecialchars($status_label) ?>
                        </span>
                    </div>
                </div>

                <?php if (!empty($r['details'])): ?>
                    <div style="margin-top: var(--space-3);">
                        <div style="font-size:0.75rem; color:var(--muted); font-weight:700; text-transform:uppercase;">Your note</div>
                        <p style="margin-top: 4px; white-space: pre-wrap;"><?= htmlspecialchars($r['details']) ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($r['post_id']) && !empty($r['post_content'])): ?>
                    <div style="margin-top: var(--space-3);">
                        <div style="font-size:0.75rem; color:var(--muted); font-weight:700; text-transform:uppercase;">
                            Post by @<?= htmlspecialchars($r['post_author_username']) ?>
                        </div>
                        <div class="report-preview" style="margin-top: 4px;">
                            "<?= htmlspecialchars(mb_substr($r['post_content'], 0, 200)) ?><?= mb_strlen($r['post_content']) > 200 ? '…' : '' ?>"
                        </div>
                    </div>
                <?php elseif (!empty($r['message_id']) && $r['msg_type'] === 'voice'): ?>
                    <div style="margin-top: var(--space-3);">
                        <div style="font-size:0.75rem; color:var(--muted); font-weight:700; text-transform:uppercase;">
                            Direct message
                        </div>
                        <div class="report-preview" style="margin-top: 4px;">
                            Voice message
                        </div>
                    </div>
                <?php elseif (!empty($r['message_id']) && !empty($r['msg_body'])): ?>
                    <div style="margin-top: var(--space-3);">
                        <div style="font-size:0.75rem; color:var(--muted); font-weight:700; text-transform:uppercase;">
                            Direct message
                        </div>
                        <div class="report-preview" style="margin-top: 4px;">
                            "<?= htmlspecialchars(mb_substr($r['msg_body'], 0, 200)) ?><?= mb_strlen($r['msg_body']) > 200 ? '…' : '' ?>"
                        </div>
                    </div>
                <?php elseif (!empty($r['conversation_id']) && empty($r['message_id'])): ?>
                    <div style="margin-top: var(--space-3);">
                        <div style="font-size:0.75rem; color:var(--muted); font-weight:700; text-transform:uppercase;">
                            Whole conversation
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($status !== 'pending' && !empty($r['resolution_note'])): ?>
                    <div style="margin-top: var(--space-4); padding: var(--space-3); background: var(--ivory); border: 1px solid var(--border); border-radius: var(--radius-md);">
                        <div style="font-size:0.75rem; color:var(--muted); font-weight:700; text-transform:uppercase;">
                            Admin response
                        </div>
                        <p style="margin-top: 4px; white-space: pre-wrap;"><?= htmlspecialchars($r['resolution_note']) ?></p>
                        <div style="font-size: 0.75rem; color: var(--muted); margin-top: var(--space-2);">
                            <?= htmlspecialchars($r['resolved_at']) ?>
                            · <a href="moderation.php">See the public moderation log</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require 'includes/footer.php'; ?>