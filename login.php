<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require_once __DIR__ . '/includes/sessions_tracker.php';

if (isset($_SESSION['user_id'])) {
    header('Location: profile.php');
    exit;
}

$message = '';
$login_message = $_SESSION['login_message'] ?? '';
unset($_SESSION['login_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    rate_limit_enforce($pdo, client_ip(), 'login', 5, 900);

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $message = __('login_error_empty');
    } else {
        $stmt = $pdo->prepare("
    SELECT id, username, password, is_admin, two_factor_enabled
    FROM users
    WHERE username = :username
");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            // ---- 2FA branch ----
            if ((int)($user['two_factor_enabled'] ?? 0) === 1) {
                $_SESSION['pending_2fa_user_id'] = (int)$user['id'];
                $_SESSION['pending_2fa_started'] = time();

                session_regenerate_id(true);

                header('Location: login_2fa.php');
                exit;
            }

            // ---- No 2FA — log in normally ----
            $_SESSION['user_id']  = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['is_admin'] = (int)($user['is_admin'] ?? 0);

            regenerate_session_on_login();

            // ---- Session tracking: register this login + housekeeping ----
            register_current_session($pdo, (int)$user['id']);
            $_SESSION['_session_registered'] = true;
            cleanup_old_sessions($pdo);

            header('Location: profile.php');
            exit;
        }

        $message = __('login_error_invalid');
    }
}

$page_title = __('login_title');
require 'includes/header.php';
?>

<h1><?= __('login_title') ?></h1>

<?php if ($login_message): ?>
    <div class="message"><?= htmlspecialchars($login_message) ?></div>
<?php endif; ?>

<?php if ($message): ?>
    <div class="message"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="card">
    <form method="POST" class="stack">
        <?= csrf_field() ?>
        <input type="text" name="username" placeholder="<?= __('login_username') ?>" required>
        <input type="password" name="password" placeholder="<?= __('login_password') ?>" required>
        <button type="submit"><?= __('login_button') ?></button>
    </form>
</div>

<p style="text-align:center;margin-top:var(--space-3);">
    <a href="forgot_password.php" style="font-size:0.9rem;color:var(--muted);">
        Forgot your password?
    </a>
</p>

<p><?= __('login_no_account') ?> <a href="register.php"><?= __('login_register_link') ?></a></p>

<?php require 'includes/footer.php'; ?>