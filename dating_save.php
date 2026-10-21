<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
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
rate_limit_enforce($pdo, client_ip(), 'dating_save', 20, 3600);

$me = (int)$_SESSION['user_id'];

try {
    save_dating_profile($pdo, $me, [
        'is_active'     => isset($_POST['is_active']) ? 1 : 0,
        'age'           => $_POST['age'] ?? 0,
        'gender'        => $_POST['gender'] ?? '',
        'interested_in' => $_POST['interested_in'] ?? '',
        'looking_for'   => $_POST['looking_for'] ?? '',
        'location'      => $_POST['location'] ?? '',
        'occupation'    => $_POST['occupation'] ?? '',
        'education'     => $_POST['education'] ?? '',
        'bio'           => $_POST['bio'] ?? '',
        'interests'     => $_POST['interests'] ?? '',
    ]);
    $_SESSION['flash_success'] = 'Dating profile saved.';
    header('Location: dating_setup.php');
    exit;
} catch (InvalidArgumentException $e) {
    $_SESSION['flash_error'] = $e->getMessage();
    header('Location: dating_setup.php');
    exit;
} catch (Throwable $e) {
    error_log('dating_save error: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Could not save. Please try again.';
    header('Location: dating_setup.php');
    exit;
}