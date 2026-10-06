<?php
session_start();
require 'config/db.php';
require 'lang/init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$message = '';
$errors = [];

// Fetch current bio
$stmt = $pdo->prepare("SELECT bio FROM users WHERE id = :id");
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch();
$bio = $user['bio'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bio = trim($_POST['bio'] ?? '');

    if (mb_strlen($bio) > 160) {
        $errors[] = __('bio_error_length') ?? 'Bio must be 160 characters or less.';
    } else {
        $stmt = $pdo->prepare("UPDATE users SET bio = :bio WHERE id = :id");
        $stmt->execute([
            ':bio' => $bio !== '' ? $bio : null,
            ':id' => $user_id,
        ]);
        $message = __('bio_saved') ?? 'Bio saved.';
    }
}

$page_title = __('bio_title') ?? 'Edit profile';
require 'includes/header.php';
?>

<h1><?= __('bio_title') ?? 'Edit profile' ?></h1>

<?php if ($message): ?>
    <div class="message"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="message" style="border-left-color: #c0392b; background: #FDECEA;">
        <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card">
    <form method="POST" class="stack">
        <?= csrf_field() ?>
        <label for="bio"><?= __('bio_label') ?? 'Bio' ?></label>
        <textarea id="bio" name="bio" maxlength="160"
                  placeholder="<?= __('bio_placeholder') ?? 'Tell people about yourself...' ?>"
                  oninput="document.getElementById('bioCount').textContent = 160 - this.value.length"><?= htmlspecialchars($bio) ?></textarea>
        <div style="text-align:right; color:var(--muted); font-size:0.85rem;">
            <span id="bioCount"><?= 160 - mb_strlen($bio) ?></span> <?= __('bio_chars_left') ?? 'characters left' ?>
        </div>
        <button type="submit"><?= __('bio_save') ?? 'Save bio' ?></button>
    </form>
</div>

<p><a href="profile.php">← <?= __('nav_profile') ?></a></p>

<?php require 'includes/footer.php'; ?>