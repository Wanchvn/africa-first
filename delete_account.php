<?php
session_start();
require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';

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
        $errors[] = __('delete_error_confirm');
    }

    if ($password === '') {
        $errors[] = __('delete_error_password');
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT password, avatar FROM users WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            $errors[] = __('delete_error_wrong_pw');
        }
    }

    if (empty($errors)) {
        $files_to_delete = [];

        if (!empty($user['avatar']) && file_exists(__DIR__ . '/' . $user['avatar'])) {
            $files_to_delete[] = __DIR__ . '/' . $user['avatar'];
        }

        $stmt = $pdo->prepare("SELECT media_path FROM posts WHERE user_id = :id AND media_path IS NOT NULL");
        $stmt->execute([':id' => $user_id]);
        foreach ($stmt->fetchAll() as $row) {
            $path = __DIR__ . '/' . $row['media_path'];
            if (file_exists($path)) {
                $files_to_delete[] = $path;
            }
        }

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
        $stmt->execute([':id' => $user_id]);

        foreach ($files_to_delete as $file) {
            @unlink($file);
        }

        session_unset();
        session_destroy();

        session_start();
        $_SESSION['login_message'] = 'Your Qarota account and all data have been permanently deleted.';
        header('Location: login.php');
        exit;
    }
}

$page_title = __('delete_title');
require 'includes/header.php';
?>

<h1><?= __('delete_title') ?></h1>

<div class="card" style="border-left: 4px solid var(--terracotta);">
    <h2 style="margin-top:0; color: var(--terracotta);">⚠ <?= __('delete_warning') ?></h2>
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
        <?= csrf_field() ?>
        <label for="password"><?= __('delete_confirm_password') ?></label>
        <input type="password" id="password" name="password" required>

        <label for="confirm"><?= __('delete_confirm_text') ?></label>
        <input type="text" id="confirm" name="confirm" placeholder="DELETE" required>

        <button type="submit" style="background: #c0392b;"><?= __('delete_button') ?></button>
    </form>
</div>

<p><a href="profile.php">← <?= __('nav_profile') ?></a></p>

<?php require 'includes/footer.php'; ?>