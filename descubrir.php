<?php
session_start();
require 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$stmt = $pdo->prepare("SELECT id, username FROM users WHERE id != :me ORDER BY created_at DESC");
$stmt->execute([':me' => $_SESSION['user_id']]);
$users = $stmt->fetchAll();

$page_title = 'Discover People';
require 'includes/header.php';
?>

<h1>Discover People</h1>

<?php if (empty($users)): ?>
    <div class="empty">No other users yet.</div>
<?php else: ?>
    <?php foreach ($users as $u): ?>
        <div class="user-row">
            <div class="name">
                <a href="perfil.php?id=<?= $u['id'] ?>">
                    <?= htmlspecialchars($u['username']) ?>
                </a>
            </div>
            <form method="POST" action="seguir.php">
                <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn-secondary">Follow / Unfollow</button>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>