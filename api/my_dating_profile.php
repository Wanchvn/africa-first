<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/dating.php';

$user = api_require_auth($pdo);
$me = (int)$user['id'];

$profile = get_dating_profile($pdo, $me);
if (!$profile || (int)$profile['is_active'] !== 1) {
    api_error(403, 'You must activate your dating profile first.');
}

$photos = list_dating_photos($pdo, $me);
$verification = get_dating_verification($pdo, $me);
$isVerified = $verification && $verification['status'] === 'approved';

$formattedPhotos = [];
foreach ($photos as $p) {
    $formattedPhotos[] = [
        'id' => (int)$p['id'],
        'url' => 'dating_photo_serve.php?id=' . $p['id']
    ];
}

api_ok([
    'photos' => $formattedPhotos,
    'verification' => [
        'status' => $verification ? $verification['status'] : 'none',
        'admin_note' => $verification ? $verification['admin_note'] : null
    ],
    'is_verified' => $isVerified
]);