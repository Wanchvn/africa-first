<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'includes/csrf.php';
require_once __DIR__ . '/includes/dating.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dating_setup.php');
    exit;
}

csrf_verify();

$me      = (int)$_SESSION['user_id'];
$photoId = (int)($_POST['photo_id'] ?? 0);

if ($photoId > 0) {
    delete_dating_photo($pdo, $photoId, $me);
}

header('Location: dating_setup.php#photos');
exit;