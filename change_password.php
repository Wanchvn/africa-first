<?php
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
$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    rate_limit_enforce($pdo, client_ip(), 'change_password', 5, 900);

    $current  = $_POST['current_password'] ?? '';
    $new      = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    // Validate
    if ($current === '') {
        $errors[] = 'Please enter your current password.';
    }
    if ($new === '') {
        $errors[] = 'Please enter a new password.';
    } elseif (mb_strlen($new) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    } elseif ($new === $current) {
        $errors[] = 'New password must be different from your current password.';
    }
    if ($new !== $confirm) {
        $errors[] = 'New password and confirmation do not match.';
    }

    // Verify current password
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $stored_hash = $stmt->fetchColumn();

        if (!$stored_hash || !password_verify($current, $stored_hash)) {
            $errors[] = 'Current password is incorrect.';
        }
    }

    // Update password
    if (empty($errors)) {
        $new_hash = password_hash($new, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("UPDATE users SET password = :password WHERE id = :id");
        $stmt->execute([
            ':password' => $new_hash,
            ':id'       => $user_id,
        ]);

        // Regenerate the session to log out any other devices
        // (this rotates the session ID — the current browser stays logged in)
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $message = 'Password updated. Use the new password next time you log in.';

        // Clear the form
        $current = $new = $confirm = '';
    }
}

$page_title = 'Change password';
require 'includes/header.php';
?>

<h1>Change password</h1>

<?php if ($message): ?>
    <div class="message" style="background: #E8F5E9; border-left-color: #4CAF50;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="message" style="border-left-color: #c0392b; background: #FDECEA;">
        <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card">
    <h2 style="margin-top:0;">Update your password</h2>
    <p style="color: var(--muted); margin-bottom: var(--space-4); font-size: 0.95rem;">
        Choose something you don't use anywhere else. A good password is at least 12 characters and includes a mix of letters, numbers, and symbols.
    </p>

    <form method="POST" class="stack">
        <?= csrf_field() ?>

        <div class="form-field">
            <label for="current_password">Current password</label>
            <div class="password-row">
                <input type="password"
                       id="current_password"
                       name="current_password"
                       autocomplete="current-password"
                       required>
                <button type="button" class="password-toggle" onclick="togglePassword('current_password', this)" aria-label="Show password">
                    <span>👁</span>
                </button>
            </div>
        </div>

        <div class="form-field">
            <label for="new_password">New password</label>
            <div class="password-row">
                <input type="password"
                       id="new_password"
                       name="new_password"
                       autocomplete="new-password"
                       minlength="8"
                       required>
                <button type="button" class="password-toggle" onclick="togglePassword('new_password', this)" aria-label="Show password">
                    <span>👁</span>
                </button>
            </div>
            <div class="password-strength" id="strengthBar">
                <div class="strength-track">
                    <div class="strength-fill" id="strengthFill"></div>
                </div>
                <div class="strength-label" id="strengthLabel"></div>
            </div>
        </div>

        <div class="form-field">
            <label for="confirm_password">Confirm new password</label>
            <div class="password-row">
                <input type="password"
                       id="confirm_password"
                       name="confirm_password"
                       autocomplete="new-password"
                       minlength="8"
                       required>
                <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', this)" aria-label="Show password">
                    <span>👁</span>
                </button>
            </div>
            <div class="field-hint" id="matchHint"></div>
        </div>

        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button type="submit">Update password</button>
            <a href="edit_profile.php" class="btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<div class="card" style="background: #FFF9F5; border-left: 4px solid var(--terracotta);">
    <h3 style="margin-top:0;">Forgot your current password?</h3>
    <p style="color: var(--muted); font-size: 0.95rem; margin-bottom: 0;">
        If you can't remember your current password, you'll need to log out and use the password reset option on the login page. (Coming soon.)
    </p>
</div>

<script>
/* ---- Password visibility toggle ---- */
function togglePassword(inputId, button) {
    var input = document.getElementById(inputId);
    var isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    button.querySelector('span').textContent = isPassword ? '🙈' : '👁';
}

/* ---- Password strength indicator ---- */
(function() {
    var input = document.getElementById('new_password');
    var fill  = document.getElementById('strengthFill');
    var label = document.getElementById('strengthLabel');
    if (!input) return;

    var LABELS = {
        weak:   'Weak',
        fair:   'Fair',
        good:   'Good',
        strong: 'Strong'
    };

    input.addEventListener('input', function() {
        var pw = input.value;
        if (pw === '') {
            fill.style.width = '0%';
            fill.className = 'strength-fill';
            label.textContent = '';
            return;
        }

        var score = 0;
        if (pw.length >= 8) score++;
        if (pw.length >= 12) score++;
        if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
        if (/[0-9]/.test(pw)) score++;
        if (/[^A-Za-z0-9]/.test(pw)) score++;

        var level;
        if (score >= 5)      level = { pct: '100%', label: LABELS.strong, cls: 'strength-strong' };
        else if (score >= 4) level = { pct: '75%',  label: LABELS.good,   cls: 'strength-good' };
        else if (score >= 3) level = { pct: '50%',  label: LABELS.fair,   cls: 'strength-fair' };
        else                 level = { pct: '25%',  label: LABELS.weak,   cls: 'strength-weak' };

        fill.style.width = level.pct;
        fill.className = 'strength-fill ' + level.cls;
        label.textContent = level.label;
        label.className = 'strength-label ' + level.cls;
    });
})();

/* ---- Confirm password match indicator ---- */
(function() {
    var newInput     = document.getElementById('new_password');
    var confirmInput = document.getElementById('confirm_password');
    var hint         = document.getElementById('matchHint');
    if (!newInput || !confirmInput) return;

    function check() {
        var n = newInput.value;
        var c = confirmInput.value;

        if (c === '') {
            hint.textContent = '';
            hint.className = 'field-hint';
            return;
        }

        if (n === c) {
            hint.textContent = '✓ Passwords match';
            hint.className = 'field-hint hint-ok';
        } else {
            hint.textContent = '✗ Passwords do not match';
            hint.className = 'field-hint hint-error';
        }
    }

    newInput.addEventListener('input', check);
    confirmInput.addEventListener('input', check);
})();
</script>

<?php require 'includes/footer.php'; ?>