<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'includes/username.php';
require 'includes/rate_limit.php';

header('Content-Type: application/json');

// Must be logged out OR checking their own username is allowed
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_username = $_SESSION['username'] ?? '';

$username = trim($_GET['u'] ?? '');

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

// If it's the same as their current username, it's "available" (no change needed)
if ($current_user_id && $username === $current_username) {
    echo json_encode(['available' => true, 'reason' => 'current']);
    exit;
}

// Rate limit: 60 checks per minute per IP
rate_limit_enforce($pdo, client_ip(), 'check_username', 60, 60);

// Check database
if (is_username_available($pdo, $username)) {
    echo json_encode(['available' => true]);
} else {
    // Suggest an alternative
    $alternative = suggest_username($pdo, $username . '@placeholder.local');
    echo json_encode([
        'available'  => false,
        'reason'     => 'taken',
        'suggestion' => $alternative,
    ]);
}