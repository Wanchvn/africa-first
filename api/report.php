<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error(405, 'Method not allowed.');

$user = api_require_admin($pdo);
$me = (int)$user['id'];

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$target_id = (int)($data['target_id'] ?? 0);
$reason = trim($data['reason'] ?? 'other');
$details = trim($data['details'] ?? '');

if ($target_id <= 0) api_error(400, 'Invalid user.');

// Insert into the reports table (assuming the table exists from your web app)
$stmt = $pdo->prepare("
    INSERT INTO reports (reporter_id, reported_user_id, reason, details, status, created_at)
    VALUES (:me, :them, :reason, :details, 'pending', NOW())
");
$stmt->execute([
    ':me' => $me, 
    ':them' => $target_id,
    ':reason' => $reason,
    ':details' => $details
]);

api_ok(['message' => 'Report submitted.']);