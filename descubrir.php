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
?>
<!DOCTYPE html>
<html>
<head><title>Discover — Baobab</title></head>
<body>
<h1>Discover People</h1>
<p>
    <a href="feed.php">Feed</a> |
    <a href="perfil.php">My Profile</a> |
    <a href="logout.php">Log out</a>
</p>

<?php foreach ($users as $u): ?>
    <div style="border:1px solid #ccc; padding:8px; margin:8px 0;">
        <a href="perfil.php?id=<?= $u['id'] ?>"><?= htmlspecialchars($u['username']) ?></a>
        <form method="POST" action="seguir.php" style="display:inline;">
            <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
            <button type="submit">Follow / Unfollow</button>
        </form>
    </div>
<?php endforeach; ?>
</body>
</html>