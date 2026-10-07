<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'includes/username.php';

header('Content-Type: application/json');

// Must be logged out (registration is a logged-out action)
if (isset($_SESSION['user_id'])) {
    echo json_encode(['available' => false, 'reason' => 'already_logged_in']);
    exit;
}

$username = trim($_GET['u'] ?? '');

// Empty or too short
if ($username === '') {
    echo json_encode(['available' => false, 'reason' => 'empty']);
    exit;
}

// Format validation
$error = validate_username($username);
if ($error !== null) {
    echo json_encode(['available' => false, 'reason' => 'invalid', 'message' => $error]);
    exit;
}

// Check database
if (is_username_available($pdo, $username)) {
    echo json_encode(['available' => true]);
} else {
    // Suggest an alternative
    $alternative = suggest_username($pdo, $username . '@placeholder.local');
    echo json_encode([
        'available'   => false,
        'reason'      => 'taken',
        'suggestion'  => $alternative,
    ]);
}