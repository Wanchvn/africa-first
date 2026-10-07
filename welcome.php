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

$user_id = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT username, avatar FROM users WHERE id = :id");
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch();
$username = $user['username'];
$avatar = $user['avatar'];

// Flash message from avatar upload
$avatar_message = $_SESSION['avatar_message'] ?? '';
unset($_SESSION['avatar_message']);

$page_title = 'Welcome';
require 'includes/header.php';
?>

<div class="welcome-hero" style="padding: var(--space-6) var(--space-4) var(--space-4);">
    <h1 class="welcome-title" style="font-size: 2rem;">
        Welcome, <?= htmlspecialchars($username) ?> 🎉
    </h1>
    <p class="welcome-subtitle">
        Your account is ready. Here's how to make the most of Qarota.
    </p>
</div>

<?php if ($avatar_message): ?>
    <div class="message" style="background: #E8F5E9; border-left-color: #4CAF50;">
        <?= htmlspecialchars($avatar_message) ?>
    </div>
<?php endif; ?>

<!-- ==================== PROFILE PICTURE CARD ==================== -->
<div class="card welcome-avatar-card">
    <div class="welcome-avatar-wrap">
        <?php if ($avatar): ?>
            <img class="avatar avatar-xl" src="<?= htmlspecialchars($avatar) ?>" alt="Your profile picture">
        <?php else: ?>
            <div class="avatar avatar-xl avatar-placeholder">
                <?= strtoupper(substr($username, 0, 1)) ?>
            </div>
        <?php endif; ?>
    </div>

    <h2 style="margin-top: var(--space-4); margin-bottom: var(--space-2);">
        <?= $avatar ? 'Looking good!' : 'Add a profile picture' ?>
    </h2>

    <?php if ($avatar): ?>
        <p style="color: var(--muted); margin-bottom: var(--space-4);">
            Your photo is set. You can change it any time from your profile.
        </p>
        <form method="POST" action="avatar.php" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="redirect" value="welcome.php">
            <label for="avatar-change" class="btn-secondary btn-small">Change photo</label>
            <input type="file" id="avatar-change" name="avatar"
                   accept="image/jpeg,image/png,image/gif,image/webp"
                   onchange="this.form.submit()" style="display:none;">
        </form>
    <?php else: ?>
        <p style="color: var(--muted); margin-bottom: var(--space-5);">
            Users with photos get <strong>3× more engagement</strong>.
            It takes 10 seconds.
        </p>

        <div class="welcome-avatar-actions">
            <form method="POST" action="avatar.php" enctype="multipart/form-data" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="redirect" value="welcome.php">
                <label for="avatar-upload" class="btn">
                    📷 Choose a photo
                </label>
                <input type="file" id="avatar-upload" name="avatar"
                       accept="image/jpeg,image/png,image/gif,image/webp"
                       onchange="this.form.submit()" style="display:none;">
            </form>

            <a href="#next-steps" class="welcome-skip">Skip for now</a>
        </div>
    <?php endif; ?>
</div>

<!-- ==================== NEXT STEPS ==================== -->
<div id="next-steps"></div>

<div class="card">
    <h2 style="margin-top:0;">1. Find people to follow</h2>
    <p style="color: var(--muted); margin-bottom: var(--space-3);">
        Your feed stays empty until you follow people. Discover shows you everyone on Qarota.
    </p>
    <a href="discover.php" class="btn-secondary btn-small">Discover people →</a>
</div>

<div class="card">
    <h2 style="margin-top:0;">2. Write your first post</h2>
    <p style="color: var(--muted); margin-bottom: var(--space-3);">
        Say hello. Tell people what you're working on. Add a photo if you want.
    </p>
    <a href="profile.php" class="btn-secondary btn-small">Write a post →</a>
</div>

<div class="card">
    <h2 style="margin-top:0;">3. Fill in your profile</h2>
    <p style="color: var(--muted); margin-bottom: var(--space-3);">
        Add your location, what you do, and the languages you speak. It helps people find you.
    </p>
    <a href="edit_profile.php" class="btn-secondary btn-small">Edit profile →</a>
</div>

<p style="text-align:center; margin-top: var(--space-5);">
    <a href="feed.php">Or go straight to your feed →</a>
</p>

<?php require 'includes/footer.php'; ?>