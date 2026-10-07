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
$message = '';
$errors = [];

// Fetch current profile data
$stmt = $pdo->prepare("
    SELECT bio, display_name, location, occupation, education, languages, interests
    FROM users
    WHERE id = :id
");
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch();

$bio          = $user['bio'] ?? '';
$display_name = $user['display_name'] ?? '';
$location     = $user['location'] ?? '';
$occupation   = $user['occupation'] ?? '';
$education    = $user['education'] ?? '';
$languages    = $user['languages'] ?? '';
$interests    = $user['interests'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $bio          = trim($_POST['bio'] ?? '');
    $display_name = trim($_POST['display_name'] ?? '');
    $location     = trim($_POST['location'] ?? '');
    $occupation   = trim($_POST['occupation'] ?? '');
    $education    = trim($_POST['education'] ?? '');
    $languages    = trim($_POST['languages'] ?? '');
    $interests    = trim($_POST['interests'] ?? '');

    // Validate lengths
    if (mb_strlen($bio) > 160)          $errors[] = 'Bio must be 160 characters or less.';
    if (mb_strlen($display_name) > 60)  $errors[] = 'Display name must be 60 characters or less.';
    if (mb_strlen($location) > 100)     $errors[] = 'Location must be 100 characters or less.';
    if (mb_strlen($occupation) > 100)   $errors[] = 'Occupation must be 100 characters or less.';
    if (mb_strlen($education) > 150)    $errors[] = 'Education must be 150 characters or less.';
    if (mb_strlen($languages) > 150)    $errors[] = 'Languages must be 150 characters or less.';
    if (mb_strlen($interests) > 200)    $errors[] = 'Interests must be 200 characters or less.';

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            UPDATE users
            SET bio = :bio,
                display_name = :display_name,
                location = :location,
                occupation = :occupation,
                education = :education,
                languages = :languages,
                interests = :interests
            WHERE id = :id
        ");
        $stmt->execute([
            ':bio'          => $bio !== '' ? $bio : null,
            ':display_name' => $display_name !== '' ? $display_name : null,
            ':location'     => $location !== '' ? $location : null,
            ':occupation'   => $occupation !== '' ? $occupation : null,
            ':education'    => $education !== '' ? $education : null,
            ':languages'    => $languages !== '' ? $languages : null,
            ':interests'    => $interests !== '' ? $interests : null,
            ':id'           => $user_id,
        ]);
        $message = 'Profile updated.';
    }
}

$page_title = 'Edit profile';
require 'includes/header.php';
?>

<h1>Edit profile</h1>

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

<form method="POST" class="stack">
    <?= csrf_field() ?>

    <div class="card">
        <h2 style="margin-top:0;">Basic information</h2>

        <div class="form-field">
            <label for="display_name">Display name <span class="optional">(optional)</span></label>
            <input type="text" id="display_name" name="display_name"
                   value="<?= htmlspecialchars($display_name) ?>"
                   maxlength="60"
                   placeholder="Your real or preferred name">
            <div class="field-hint">
                Shown instead of your username on posts, comments, and your profile.
            </div>
        </div>

        <div class="form-field">
            <label for="bio">Bio <span class="optional">(optional)</span></label>
            <textarea id="bio" name="bio" maxlength="160"
                      placeholder="A sentence or two about yourself."
                      oninput="document.getElementById('bioCount').textContent = 160 - this.value.length"><?= htmlspecialchars($bio) ?></textarea>
            <div class="field-hint" style="text-align:right;">
                <span id="bioCount"><?= 160 - mb_strlen($bio) ?></span> characters left
            </div>
        </div>
    </div>

    <div class="card">
        <h2 style="margin-top:0;">Where you are</h2>

        <div class="form-field">
            <label for="location">Location <span class="optional">(optional)</span></label>
            <input type="text" id="location" name="location"
                   value="<?= htmlspecialchars($location) ?>"
                   maxlength="100"
                   placeholder="e.g. Tamale, Ghana">
        </div>

        <div class="form-field">
            <label for="languages">Languages <span class="optional">(optional)</span></label>
            <input type="text" id="languages" name="languages"
                   value="<?= htmlspecialchars($languages) ?>"
                   maxlength="150"
                   placeholder="e.g. Twi, English, Dagbani">
            <div class="field-hint">Separate with commas.</div>
        </div>
    </div>

    <div class="card">
        <h2 style="margin-top:0;">What you do</h2>

        <div class="form-field">
            <label for="occupation">Occupation <span class="optional">(optional)</span></label>
            <input type="text" id="occupation" name="occupation"
                   value="<?= htmlspecialchars($occupation) ?>"
                   maxlength="100"
                   placeholder="e.g. Software Developer">
        </div>

        <div class="form-field">
            <label for="education">Education <span class="optional">(optional)</span></label>
            <input type="text" id="education" name="education"
                   value="<?= htmlspecialchars($education) ?>"
                   maxlength="150"
                   placeholder="e.g. University for Development Studies">
        </div>

        <div class="form-field">
            <label for="interests">Interests <span class="optional">(optional)</span></label>
            <input type="text" id="interests" name="interests"
                   value="<?= htmlspecialchars($interests) ?>"
                   maxlength="200"
                   placeholder="e.g. football, coding, music">
            <div class="field-hint">Separate with commas.</div>
        </div>
    </div>

    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <button type="submit">Save profile</button>
        <a href="profile.php" class="btn-secondary">Cancel</a>
    </div>

</form>

<?php require 'includes/footer.php'; ?>