<?php
session_start();
require 'config/db.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($username) || empty($email) || empty($password)) {
        $message = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Invalid email address.';
    } elseif (strlen($password) < 8) {
        $message = 'Password must be at least 8 characters.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username OR email = :email");
        $stmt->execute([':username' => $username, ':email' => $email]);

        if ($stmt->fetch()) {
            $message = 'Username or email already taken.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (:username, :email, :password)");
            $stmt->execute([':username' => $username, ':email' => $email, ':password' => $hash]);
            $message = 'Account created. You can now log in.';
        }
    }
}

$page_title = 'Join Qarota';
require 'includes/header.php';
?>

<h1>Join Qarota</h1>

<?php if ($message): ?>
    <div class="message"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="card">
    <form method="POST" class="stack">
        <input type="text" name="username" placeholder="Username" required>
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password (min 8 characters)" required>
        <button type="submit">Create Account</button>
    </form>
</div>

<p>Already have an account? <a href="login.php">Log in</a></p>

<?php require 'includes/footer.php'; ?>