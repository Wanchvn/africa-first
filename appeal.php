<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Load user + ban state
$stmt = $pdo->prepare("
    SELECT id, username, display_name, is_banned, ban_reason, banned_at
    FROM users
    WHERE id = :id LIMIT 1
");
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Only banned users can see this page
if (!(int)$user['is_banned']) {
    header('Location: feed.php');
    exit;
}

// Load any existing appeal
$stmt = $pdo->prepare("
    SELECT id, ban_reason, appeal_text, status, admin_reply, resolved_at, created_at
    FROM appeals
    WHERE user_id = :uid
    ORDER BY created_at DESC
    LIMIT 1
");
$stmt->execute([':uid' => $user_id]);
$existing_appeal = $stmt->fetch(PDO::FETCH_ASSOC);

$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    rate_limit_enforce($pdo, client_ip(), 'appeal_submit', 3, 3600);

    // Block double-submit if there's already a pending appeal
    if ($existing_appeal && $existing_appeal['status'] === 'pending') {
        $errors[] = 'You already have a pending appeal. Please wait for an admin to review it.';
    } else {
        $appeal_text = trim($_POST['appeal_text'] ?? '');

        if ($appeal_text === '') {
            $errors[] = 'Please write your appeal before submitting.';
        } elseif (mb_strlen($appeal_text) < 20) {
            $errors[] = 'Your appeal should be at least 20 characters.';
        } elseif (mb_strlen($appeal_text) > 2000) {
            $errors[] = 'Your appeal must be under 2000 characters.';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("
                INSERT INTO appeals (user_id, ban_reason, appeal_text, status, created_at)
                VALUES (:uid, :reason, :text, 'pending', NOW())
            ");
            $stmt->execute([
                ':uid'    => $user_id,
                ':reason' => (string)($user['ban_reason'] ?? 'Not specified'),
                ':text'   => $appeal_text,
            ]);

            $success = 'Your appeal has been submitted. An admin will review it soon.';

            // Reload the appeal
            $stmt = $pdo->prepare("
                SELECT id, ban_reason, appeal_text, status, admin_reply, resolved_at, created_at
                FROM appeals WHERE user_id = :uid ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([':uid' => $user_id]);
            $existing_appeal = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
}

$page_title = 'Appeal your ban';
require 'includes/header.php';
?>

<div class="container" style="max-width:640px;">
    <div class="card" style="border-left:4px solid var(--danger);">
        <h1 style="margin-top:0;">You've been banned</h1>
        <p style="color: var(--muted); font-size: 0.95rem;">
            Your account has been suspended. You can submit an appeal below.
            An admin will review it and respond.
        </p>

        <div style="margin-top: var(--space-4);">
            <div style="font-size:0.85rem; color: var(--muted); text-transform:uppercase; letter-spacing:0.05em; font-weight:700;">
                Reason
            </div>
            <p style="margin-top: var(--space-1);"><?= htmlspecialchars($user['ban_reason'] ?: 'Not specified') ?></p>

            <?php if (!empty($user['banned_at'])): ?>
                <div style="font-size:0.85rem; color: var(--muted); margin-top: var(--space-2);">
                    Banned on <?= htmlspecialchars($user['banned_at']) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

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

    <?php if ($existing_appeal): ?>
        <div class="card">
            <h2 style="margin-top:0;">Your appeal</h2>

            <div style="font-size:0.8rem; color:var(--muted);">
                Submitted <?= htmlspecialchars($existing_appeal['created_at']) ?>
            </div>

            <div style="margin-top: var(--space-3); padding: var(--space-3); background:var(--sand); border-radius: var(--radius-md); white-space:pre-wrap;">
                <?= htmlspecialchars($existing_appeal['appeal_text']) ?>
            </div>

            <div style="margin-top: var(--space-4);">
                <?php if ($existing_appeal['status'] === 'pending'): ?>
                    <span class="post-visibility-badge" style="background:#FFF4E5; color:var(--clay);">
                        ⏳ Pending review
                    </span>
                <?php elseif ($existing_appeal['status'] === 'approved'): ?>
                    <span class="post-visibility-badge" style="background:#E8F5E9; color:var(--baobab-green);">
                        ✓ Approved
                    </span>
                    <p style="margin-top: var(--space-2);">Your ban has been lifted. Refresh to access the site.</p>
                <?php elseif ($existing_appeal['status'] === 'rejected'): ?>
                    <span class="post-visibility-badge" style="background:#FDECEA; color:var(--danger);">
                        ✕ Rejected
                    </span>
                <?php endif; ?>

                <?php if (!empty($existing_appeal['admin_reply'])): ?>
                    <div style="margin-top: var(--space-3); padding: var(--space-3); background:var(--ivory); border:1px solid var(--border); border-radius: var(--radius-md);">
                        <div style="font-size:0.8rem; color:var(--muted); text-transform:uppercase; font-weight:700;">
                            Admin reply
                        </div>
                        <p style="margin-top: var(--space-1); white-space:pre-wrap;"><?= htmlspecialchars($existing_appeal['admin_reply']) ?></p>
                        <?php if (!empty($existing_appeal['resolved_at'])): ?>
                            <div style="font-size:0.75rem; color:var(--muted); margin-top: var(--space-2);">
                                <?= htmlspecialchars($existing_appeal['resolved_at']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($existing_appeal['status'] === 'pending'): ?>
                <p style="color: var(--muted); font-size: 0.85rem; margin-top: var(--space-4);">
                    You can't submit another appeal while this one is pending.
                </p>
            <?php else: ?>
                <p style="color: var(--muted); font-size: 0.85rem; margin-top: var(--space-4);">
                    You may submit a new appeal if your situation has changed.
                </p>
            <?php endif; ?>
        </div>

        <?php if ($existing_appeal['status'] !== 'pending'): ?>
            <!-- Allow resubmission -->
            <div class="card">
                <h2 style="margin-top:0;">Submit a new appeal</h2>
                <form method="POST" class="stack">
                    <?= csrf_field() ?>
                    <textarea name="appeal_text" maxlength="2000" rows="6"
                              placeholder="Explain why your ban should be reconsidered…"></textarea>
                    <button type="submit">Submit appeal</button>
                </form>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="card">
            <h2 style="margin-top:0;">Submit an appeal</h2>
            <p style="color: var(--muted); font-size: 0.9rem;">
                Be specific. Tell us what happened, why you think the ban was a mistake,
                or what you'd do differently. Admins read every appeal.
            </p>
            <form method="POST" class="stack">
                <?= csrf_field() ?>
                <textarea name="appeal_text" maxlength="2000" rows="8" required
                          placeholder="Your appeal…"></textarea>
                <button type="submit">Submit appeal</button>
            </form>
        </div>
    <?php endif; ?>

    <p style="text-align:center; font-size:0.85rem; color: var(--muted); margin-top: var(--space-5);">
        Every appeal and resolution is logged publicly.
        <a href="moderation.php">See the moderation log →</a>
    </p>
</div>

<?php require 'includes/footer.php'; ?>