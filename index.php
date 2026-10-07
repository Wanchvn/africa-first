<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

// Logged in → go to feed
if (isset($_SESSION['user_id'])) {
    header('Location: feed.php');
    exit;
}

// Not logged in → go to login
header('Location: login.php');
exit;