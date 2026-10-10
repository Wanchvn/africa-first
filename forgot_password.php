<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require 'includes/mailer.php';

if (isset($_SESSION['user_id'])) {
    header('Location: feed.php');
    exit;
}

$message = '';
$errors = [];
$submitted_email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    rate_limit_enforce($pdo, client_ip(), 'forgot_password', 3, 900);

    $submitted_email = trim($_POST['email'] ?? '');

    if ($submitted_email === '') {
        $errors[] = 'Please enter your email address.';
    } elseif (!filter_var($submitted_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'That email address is not valid.';
    }

    if (empty($errors)) {
        // Look up the user
        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = :email");
        $stmt->execute([':email' => $submitted_email]);
        $user = $stmt->fetch();

        // Whether or not the email exists, show the same message
        $message = 'If an account exists for that email, we have sent a password reset link. Check your inbox and spam folder.';

        if ($user) {
            // Delete any existing unused tokens
            $stmt = $pdo->prepare("DELETE FROM password_resets WHERE user_id = :id");
            $stmt->execute([':id' => $user['id']]);

            // Generate token
            $raw_token = bin2hex(random_bytes(32));
            $token_hash = hash('sha256', $raw_token);
            $expires_at = date('Y-m-d H:i:s', time() + 3600);

            $stmt = $pdo->prepare("
                INSERT INTO password_resets (user_id, token_hash, expires_at)
                VALUES (:user_id, :token_hash, :expires_at)
            ");
            $stmt->execute([
                ':user_id'    => $user['id'],
                ':token_hash' => $token_hash,
                ':expires_at' => $expires_at,
            ]);

            // Build reset URL
            $base_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
            $reset_url = $base_url . dirname($_SERVER['PHP_SELF']) . '/reset_password.php?token=' . $raw_token;

            // Send email
            $body = password_reset_email($user['username'], $reset_url);
            send_email($submitted_email, $user['username'], 'Reset your Qarota password', $body);
        }
    }
}

$page_title = 'Forgot password';
require 'includes/header.php';
?>

<div class="welcome-hero">
    <h1 class="welcome-title">Forgot your password?</h1>
    <p class="welcome-subtitle">
        Enter the email you used to sign up. We'll send you a link to set a new password.
    </p>
</div>

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

<?php if (!$message): ?>
    <div class="card">
        <form method="POST" class="stack">
            <?= csrf_field() ?>
            <div class="form-field">
                <label for="email">Email address</label>
                <input type="email" id="email" name="email"
                       value="<?= htmlspecialchars($submitted_email) ?>"
                       placeholder="you@example.com"
                       autocomplete="email" required>
            </div>
            <button type="submit">Send reset link</button>
        </form>
    </div>
<?php endif; ?>

<p style="text-align:center;">
    <a href="login.php">← Back to login</a>
</p>

<?php require 'includes/footer.php'; ?>