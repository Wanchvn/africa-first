<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    rate_limit_enforce($pdo, client_ip(), 'register', 3, 3600);

    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($username) || empty($email) || empty($password)) {
        $message = __('register_error_required');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = __('register_error_email');
    } elseif (mb_strlen($password) < 8) {
        $message = __('register_error_password');
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username OR email = :email");
        $stmt->execute([':username' => $username, ':email' => $email]);

        if ($stmt->fetch()) {
            $message = __('register_error_taken');
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (:username, :email, :password)");
            $stmt->execute([':username' => $username, ':email' => $email, ':password' => $hash]);
            $message = __('register_success');
        }
    }
}

$page_title = __('register_title');
require 'includes/header.php';
?>

<h1><?= __('register_title') ?></h1>

<?php if ($message): ?>
    <div class="message"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="card">
    <form method="POST" class="stack">
        <?= csrf_field() ?>
        <input type="text" name="username" placeholder="<?= __('login_username') ?>" required>
        <input type="email" name="email" placeholder="<?= __('register_email') ?>" required>
        <input type="password" name="password" placeholder="<?= __('register_password_hint') ?>" required>
        <button type="submit"><?= __('register_button') ?></button>
    </form>
</div>

<p><?= __('register_have_account') ?> <a href="login.php"><?= __('register_login_link') ?></a></p>

<?php require 'includes/footer.php'; ?>