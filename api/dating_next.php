<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/dating.php';

$user = api_require_admin($pdo);
$me = (int)$user['id'];

if (!is_dating_active($pdo, $me)) {
    api_error(403, 'You are not an active dating member.');
}

// fetch_swipe_candidate() returns a row or null.
$candidate = fetch_swipe_candidate($pdo, $me);

if (!$candidate) {
    api_ok(['candidate' => null]); // Deck is empty
}

// Fetch their primary photo (from Phase 3)
$primaryPhoto = get_primary_dating_photo($pdo, (int)$candidate['user_id']);
$photoUrl = null;
if ($primaryPhoto) {
    // We need the photo ID to serve it through dating_photo_serve.php
    $stmt = $pdo->prepare("SELECT id FROM dating_photos WHERE user_id = :u ORDER BY position ASC, id ASC LIMIT 1");
    $stmt->execute([':u' => (int)$candidate['user_id']]);
    $photoId = (int)$stmt->fetchColumn();
    if ($photoId > 0) $photoUrl = "dating_photo_serve.php?id=" . $photoId;
}

// Check if verified
$isVerified = is_dating_verified($pdo, (int)$candidate['user_id']);

api_ok([
    'candidate' => [
        'user_id' => (int)$candidate['user_id'],
        'name' => $candidate['display_name'] ?: $candidate['username'],
        'age' => (int)$candidate['age'],
        'location' => $candidate['location'],
        'looking_for' => dating_looking_label($candidate['looking_for'] ?? ''),
        'bio' => $candidate['bio'],
        'avatar' => $candidate['avatar'],
        'primary_photo' => $photoUrl,
        'is_verified' => $isVerified
    ]
]);