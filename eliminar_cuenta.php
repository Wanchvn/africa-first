<?php
session_start();
require 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if ($confirm !== 'DELETE') {
        $errors[] = 'Please type DELETE exactly to confirm.';
    }

    if ($password === '') {
        $errors[] = 'Please enter your password.';
    }

    // Verify password
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT password, avatar FROM users WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            $errors[] = 'Incorrect password.';
        }
    }

    // Everything checks out — delete
    if (empty($errors)) {
        // 1. Gather file paths BEFORE deleting DB rows
        $files_to_delete = [];

        // Avatar
        if (!empty($user['avatar']) && file_exists(__DIR__ . '/' . $user['avatar'])) {
            $files_to_delete[] = __DIR__ . '/' . $user['avatar'];
        }

        // Post images
        $stmt = $pdo->prepare("SELECT media_path FROM posts WHERE user_id = :id AND media_path IS NOT NULL");
        $stmt->execute([':id' => $user_id]);
        foreach ($stmt->fetchAll() as $row) {
            $path = __DIR__ . '/' . $row['media_path'];
            if (file_exists($path)) {
                $files_to_delete[] = $path;
            }
        }

        // 2. Delete the user — cascades wipe posts, likes, comments, follows, notifications
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
        $stmt->execute([':id' => $user_id]);

        // 3. Delete files from disk (after DB delete succeeds)
        foreach ($files_to_delete as $file) {
            @unlink($file);
        }

        // 4. Log out
        session_unset();
        session_destroy();

        // 5. Redirect with farewell message
        session_start();
        $_SESSION['login_message'] = 'Your Qarota account and all data have been permanently deleted.';
        header('Location: login.php');
        exit;
    }
}

$page_title = 'Delete Account';
require 'includes/header.php';
?>

<h1>Delete your account</h1>

<div class="card" style="border-left: 4px solid var(--terracotta);">
    <h2 style="margin-top:0; color: var(--terracotta);">⚠ This cannot be undone</h2>
    <p>Deleting your account will permanently remove:</p>
    <ul style="margin: 10px 0 10px 20px;">
        <li>Your profile and avatar</li>
        <li>All your posts and their photos</li>
        <li>All your comments and likes</li>
        <li>Your follow relationships</li>
        <li>Your notification history</li>
    </ul>
    <p><strong>There is no recovery. There is no grace period.</strong> This is instant and permanent.</p>
    <p>If you'd like a copy of your data first, <a href="exportar.php">download your export</a> before continuing.</p>
</div>

<?php if (!empty($errors)): ?>
    <div class="message" style="border-left-color: #c0392b; background: #FDECEA;">
        <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card">
    <form method="POST" class="stack">
        <label for="password">Confirm your password</label>
        <input type="password" id="password" name="password" required>

        <label for="confirm">Type <code>DELETE</code> to confirm</label>
        <input type="text" id="confirm" name="confirm" placeholder="DELETE" required>

        <button type="submit" style="background: #c0392b;">Permanently delete my account</button>
    </form>
</div>

<p><a href="perfil.php">Cancel — take me back to my profile</a></p>

<?php require 'includes/footer.php'; ?>