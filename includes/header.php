<?php

$logged_in = isset($_SESSION['user_id']);

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
   <?php if ($logged_in): ?>
    <a href="feed.php">Feed</a>
    <a href="descubrir.php">Discover</a>
    <a href="perfil.php">My Profile</a>
    <a href="logout.php" style="margin-left:auto;">Log out</a>
<?php else: ?>
    <a href="login.php" style="margin-left:auto;">Log in</a>
    <a href="registro.php">Register</a>
<?php endif; ?>
</header>
<main class="container">