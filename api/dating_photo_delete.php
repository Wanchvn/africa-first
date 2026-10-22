<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/dating.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error(405, 'Method not allowed.');

$user = api_require_auth($pdo);
$me = (int)$user['id'];

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$photoId = (int)($data['photo_id'] ?? 0);

if ($photoId <= 0) api_error(400, 'Invalid photo ID.');

if (delete_dating_photo($pdo, $photoId, $me)) {
    api_ok(['message' => 'Photo deleted.']);
} else {
    api_error(403, 'Could not delete photo.');
}