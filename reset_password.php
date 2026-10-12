<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';

if (isset($_SESSION['user_id'])) {
    header('Location: feed.php');
    exit;
}

$raw_token = trim($_GET['token'] ?? '');
$message = '';
$errors = [];
$valid_token = false;
$user_id = 0;

if ($raw_token === '') {
    $errors[] = 'No reset token provided.';
} else {
    $token_hash = hash('sha256', $raw_token);

    $stmt = $pdo->prepare("
        SELECT pr.id, pr.user_id, pr.expires_at, pr.used
        FROM password_resets pr
        WHERE pr.token_hash = :h
    ");
    $stmt->execute([':h' => $token_hash]);
    $reset = $stmt->fetch();

    if (!$reset) {
        $errors[] = 'This reset link is invalid or has already been used.';
    } elseif ($reset['used']) {
        $errors[] = 'This reset link has already been used. Request a new one.';
    } elseif (strtotime($reset['expires_at']) <= time()) {
        $errors[] = 'This reset link has expired. Request a new one.';
    } else {
        $valid_token = true;
        $user_id = (int)$reset['user_id'];
    }
}

// Remove expired tokens opportunistically while preserving the specific expiry message above.
$pdo->exec("DELETE FROM password_resets WHERE expires_at <= CURRENT_TIMESTAMP");

if ($valid_token && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    rate_limit_enforce($pdo, client_ip(), 'reset_password', 5, 900);

    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($new === '') {
        $errors[] = 'Please enter a new password.';
    } elseif (mb_strlen($new) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($new !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        $hash = password_hash($new, PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                SELECT id, user_id, expires_at, used
                FROM password_resets
                WHERE token_hash = :h
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([':h' => $token_hash]);
            $reset = $stmt->fetch();

            if (
                !$reset
                || (int)$reset['user_id'] !== $user_id
                || !empty($reset['used'])
                || strtotime($reset['expires_at']) <= time()
            ) {
                $pdo->rollBack();
                $valid_token = false;
                $errors[] = 'This reset link is invalid, expired, or has already been used. Request a new one.';
            } else {
                $stmt = $pdo->prepare("UPDATE users SET password = :p WHERE id = :id");
                $stmt->execute([':p' => $hash, ':id' => $user_id]);

                $stmt = $pdo->prepare("DELETE FROM password_resets WHERE id = :id");
                $stmt->execute([':id' => $reset['id']]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Password reset token could not be consumed');
                }

                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($valid_token) {
            $_SESSION['login_message'] = 'Password updated. You can now log in with your new password.';
            header('Location: login.php');
            exit;
        }
    }
}

$page_title = 'Reset password';
require 'includes/header.php';
?>

<h1>Set a new password</h1>

<?php if (!empty($errors)): ?>
    <div class="message" style="border-left-color:#c0392b;background:#FDECEA;">
        <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($valid_token): ?>
    <div class="card">
        <form method="POST" class="stack">
            <?= csrf_field() ?>
            <div class="form-field">
                <label for="new_password">New password</label>
                <input type="password" id="new_password" name="new_password"
                       minlength="8" autocomplete="new-password" required>
            </div>
            <div class="form-field">
                <label for="confirm_password">Confirm new password</label>
                <input type="password" id="confirm_password" name="confirm_password"
                       minlength="8" autocomplete="new-password" required>
            </div>
            <button type="submit">Set new password</button>
        </form>
    </div>
<?php else: ?>
    <p>
        <a href="forgot_password.php">Request a new reset link →</a>
    </p>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>