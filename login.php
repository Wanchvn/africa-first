<?php
session_start();
require 'config/db.php';

if (isset($_SESSION['user_id'])) {
    header('Location: perfil.php');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $message = 'Please enter both username and password.';
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
            $message = 'Invalid username or password.';
        }
    }
}

$page_title = 'Log In';

// Show farewell message if redirected from deletion
$login_message = $_SESSION['login_message'] ?? '';
unset($_SESSION['login_message']);

require 'includes/header.php';
?>

<h1>Log in to Qarota</h1>

<?php if ($login_message): ?>
    <div class="message"><?= htmlspecialchars($login_message) ?></div>
<?php endif; ?>

<?php if ($message): ?>
    <div class="message"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="card">
    <form method="POST" class="stack">
        <input type="text" name="username" placeholder="Username" required>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit">Log In</button>
    </form>
</div>

<p>Need an account? <a href="registro.php">Register</a></p>

<?php require 'includes/footer.php'; ?>