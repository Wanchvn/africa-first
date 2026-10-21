<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error(405, 'Method not allowed.');
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        api_error(400, 'Invalid JSON body.');
    }
} else {
    $data = $_POST;
}

$identifier = trim((string)($data['username'] ?? ''));
$password   = (string)($data['password'] ?? '');
$deviceName = trim((string)($data['device_name'] ?? 'mobile'));

if ($identifier === '' || $password === '') {
    api_error(400, 'Username and password are required.');
}

try {
    rate_limit_enforce($pdo, client_ip(), 'api_login', 10, 60);
} catch (Throwable $e) {
    api_error(429, 'Too many attempts. Slow down.');
}

$stmt = $pdo->prepare("
    SELECT id, username, password, is_banned, ban_reason, two_factor_enabled
    FROM users
    WHERE username = :i OR email = :i
    LIMIT 1
");
$stmt->execute([':i' => $identifier]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password'])) {
    api_error(401, 'Wrong username or password.');
}
if ((int)$user['is_banned'] === 1) {
    api_error(403, 'Account suspended. ' . ($user['ban_reason'] ?? ''));
}

// --- NEW: TOTP 2FA CHECK ---
if (isset($user['two_factor_enabled']) && (int)$user['two_factor_enabled'] === 1) {
    // Tell the app to show the Authenticator screen!
    api_ok([
        'requires_2fa' => true,
        'user_id'      => (int)$user['id']
    ]);
    exit; 
}
// ---------------------------

$issued = api_issue_token($pdo, (int)$user['id'], $deviceName);

api_ok([
    'token' => $issued['token'],
    'user'  => [
        'id'           => (int)$user['id'],
        'username'     => $user['username'],
    ],
]);