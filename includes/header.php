<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$logged_in = isset($_SESSION['user_id']);

// Count unread notifications (only if logged in)
$unread_count = 0;
if ($logged_in) {
    require_once __DIR__ . '/../config/db.php';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :me AND is_read = 0");
    $stmt->execute([':me' => $_SESSION['user_id']]);
    $unread_count = (int)$stmt->fetchColumn();
}

if (!isset($page_title)) $page_title = 'Qarota';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> — Qarota</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="site-header">
    <nav>
        <a href="feed.php" class="brand">Qarota</a>
        <?php if ($logged_in): ?>
            <a href="feed.php">Feed</a>
            <a href="notificaciones.php" class="nav-notifications">
                Notifications
                <?php if ($unread_count > 0): ?>
                    <span class="badge"><?= $unread_count ?></span>
                <?php endif; ?>
            </a>
            <a href="descubrir.php">Discover</a>
            <a href="perfil.php">My Profile</a>
            <a href="logout.php" style="margin-left:auto;">Log out</a>
        <?php else: ?>
            <a href="login.php" style="margin-left:auto;">Log in</a>
            <a href="registro.php">Register</a>
        <?php endif; ?>
    </nav>
</header>
<main class="container">