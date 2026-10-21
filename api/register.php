<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

// Read JSON payload
$input = json_decode(file_get_contents('php://input'), true);

$username     = trim($input['username'] ?? '');
$email        = trim($input['email'] ?? '');
$password     = $input['password'] ?? '';
$display_name = trim($input['display_name'] ?? '');

// 1. Basic Validation
if (empty($username) || empty($email) || empty($password)) {
    echo json_encode(['success' => false, 'error' => 'Please fill in all required fields.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']);
    exit;
}

if (strlen($password) < 8) {
    echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters long.']);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
    echo json_encode(['success' => false, 'error' => 'Username can only contain letters, numbers, and underscores.']);
    exit;
}

try {
    // 2. Check if Username or Email already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :u OR email = :e LIMIT 1");
    $stmt->execute([':u' => $username, ':e' => $email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Username or email is already taken.']);
        exit;
    }

    // 3. Hash the password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // 4. Insert the new user
    $stmt = $pdo->prepare("
        INSERT INTO users (username, email, password, display_name, created_at)
        VALUES (:u, :e, :p, :d, NOW())
    ");
    
    $stmt->execute([
        ':u' => $username,
        ':e' => $email,
        ':p' => $hashed_password,
        ':d' => $display_name ?: $username
    ]);

    // 5. Return success (You can optionally log them in immediately by generating a token here)
    echo json_encode(['success' => true, 'message' => 'Account created successfully.']);

} catch (Throwable $e) {
    error_log('Registration error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error. Please try again.']);
}