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

if (!is_dating_active($pdo, $me)) {
    header('Location: dating_setup.php');
    exit;
}

$current = get_dating_verification($pdo, $me);

// If approved and not expired, no need to show the form.
$isVerified = $current && $current['status'] === 'approved';

// Generate / fetch a code for the current attempt. If they have a pending
// submission, show that status. Otherwise, generate a fresh code and store
// it in the session so the same code is used for the upload.
if (session_status() === PHP_SESSION_NONE) start_secure_session();
if (empty($_SESSION['dating_verify_code']) || !$current || $current['status'] !== 'pending') {
    $_SESSION['dating_verify_code'] = generate_dating_code();
}
$code = $_SESSION['dating_verify_code'];

$flash_error   = $_SESSION['flash_error']   ?? '';
$flash_success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

$page_title = 'Verify your profile';
require 'includes/header.php';
?>

<div class="dating-container" style="max-width:640px;">
    <div class="dating-header">
        <h1>Verify your profile</h1>
        <p class="dating-subtitle">
            Prove you're the person in your photos. It only takes a minute,
            and it makes your profile 3x more likely to get a reply.
        </p>
    </div>

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

    <?php if ($isVerified): ?>
        <div class="card" style="text-align:center;">
            <div style="font-size:3rem; margin-bottom:8px;">✓</div>
            <h2 style="margin-top:0;">You're verified</h2>
            <p style="color:var(--muted);">
                Your dating profile shows a verified badge.
                Verified <?= htmlspecialchars(substr($current['resolved_at'], 0, 10)) ?>.
            </p>
        </div>

    <?php elseif ($current && $current['status'] === 'pending'): ?>
        <div class="card">
            <h2 style="margin-top:0;">Under review</h2>
            <p style="color:var(--muted);">
                We received your selfie. An admin will review it within 24 hours.
                You'll see your badge appear once approved.
            </p>
            <p style="color:var(--muted); font-size:0.9rem;">
                Submitted <?= htmlspecialchars($current['submitted_at']) ?>
            </p>
            <img src="dating_verification_serve.php?id=<?= (int)$current['id'] ?>" alt="" style="max-width:240px; border-radius:8px;">
        </div>

    <?php elseif ($current && $current['status'] === 'rejected'): ?>
        <div class="card" style="border-left:4px solid var(--danger);">
            <h2 style="margin-top:0;">Verification was not approved</h2>
            <?php if (!empty($current['admin_note'])): ?>
                <p><strong>Admin note:</strong> <?= htmlspecialchars($current['admin_note']) ?></p>
            <?php endif; ?>
            <p style="color:var(--muted);">
                You can try again below. Make sure your face is clearly visible
                and the code is readable in the photo.
            </p>
        </div>
        <?php // fall through to the form ?>
    <?php endif; ?>

    <?php if (!$isVerified && (!$current || $current['status'] !== 'pending')): ?>
        <div class="card">
            <h2 style="margin-top:0;">How it works</h2>
            <ol style="padding-left:22px; color:var(--deep-brown);">
                <li>Write the code below on a piece of paper.</li>
                <li>Hold the paper up next to your face.</li>
                <li>Take a selfie clearly showing both.</li>
                <li>Upload it. An admin reviews within 24 hours.</li>
            </ol>

            <div style="text-align:center; margin: var(--space-4) 0;">
                <div style="font-size:2rem; font-weight:700; letter-spacing:0.2em; color:var(--terracotta);">
                    <?= htmlspecialchars($code) ?>
                </div>
                <div style="color:var(--muted); font-size:0.85rem; margin-top:4px;">
                    Write this exact code on paper.
                </div>
            </div>

            <form method="POST" action="dating_verify_submit.php" enctype="multipart/form-data" class="stack">
                <?= csrf_field() ?>
                <input type="hidden" name="code" value="<?= htmlspecialchars($code) ?>">

                <div class="form-field">
                    <label for="selfie">Your selfie</label>
                    <input type="file" id="selfie" name="selfie"
                           accept="image/jpeg,image/png,image/webp" required>
                    <div class="field-hint">
                        Make sure your face and the paper with the code are both clearly visible.
                        Max 8 MB. JPG, PNG, or WebP.
                    </div>
                </div>

                <button type="submit">Submit for review</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php require 'includes/footer.php'; ?>