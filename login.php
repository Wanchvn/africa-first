<?php
session_start();
require 'config/db.php';
require 'lang/init.php';

if (isset($_SESSION['user_id'])) {
    header('Location: perfil.php');
    exit;
}

$message = '';
$login_message = $_SESSION['login_message'] ?? '';
unset($_SESSION['login_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $message = __('login_error_empty');
    } else {
        $stmt = $pdo->prepare("SELECT id, username, password FROM users WHERE username = :username");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            header('Location: perfil.php');
            exit;
        } else {
            $message = __('login_error_invalid');
        }
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
        <input type="text" name="username" placeholder="<?= __('login_username') ?>" required>
        <input type="password" name="password" placeholder="<?= __('login_password') ?>" required>
        <button type="submit"><?= __('login_button') ?></button>
    </form>
</div>

<p><?= __('login_no_account') ?> <a href="registro.php"><?= __('login_register_link') ?></a></p>

<?php require 'includes/footer.php'; ?>