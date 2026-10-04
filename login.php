<?php
session_start();
require 'config/db.php';

// If already logged in, go straight to profile
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
            // Success — start the session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            header('Location: perfil.php');
            exit;
        } else {
            $message = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head><title>Login — Baobab</title></head>
<body>
<h1>Login to Baobab</h1>
<?php if ($message): ?><p><?= htmlspecialchars($message) ?></p><?php endif; ?>
<form method="POST">
    <input type="text" name="username" placeholder="Username" required><br>
    <input type="password" name="password" placeholder="Password" required><br>
    <button type="submit">Log In</button>
</form>
<p><a href="registro.php">Need an account? Register</a></p>
</body>
</html>