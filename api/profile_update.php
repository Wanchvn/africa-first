<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error(405, 'Method not allowed.');

$user = api_require_admin($pdo);
$me = (int)$user['id'];

$bio = trim($_POST['bio'] ?? '');
$avatar_path = $user['avatar']; // Keep existing by default

// Handle Avatar Upload
if (!empty($_FILES['avatar']['name']) && ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $file = $_FILES['avatar'];
    $max_size = 3 * 1024 * 1024; // 3MB

    if ($file['size'] > $max_size) {
        api_error(400, 'Avatar must be smaller than 3 MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    
    if (!in_array($mime, $allowed)) {
        api_error(400, 'Only JPG, PNG, or WebP images are allowed.');
    }

    $ext = match($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    };

    $filename = 'avatar_' . $me . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $destination = __DIR__ . '/../uploads/avatars/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        // Delete old avatar if it exists
        if ($avatar_path && file_exists(__DIR__ . '/../' . $avatar_path)) {
            @unlink(__DIR__ . '/../' . $avatar_path);
        }
        $avatar_path = 'uploads/avatars/' . $filename;
    } else {
        api_error(500, 'Could not save avatar.');
    }
}

// Update DB
$stmt = $pdo->prepare("UPDATE users SET bio = :bio, avatar = :avatar WHERE id = :id");
$stmt->execute([':bio' => $bio, ':avatar' => $avatar_path, ':id' => $me]);

api_ok(['message' => 'Profile updated.', 'avatar' => $avatar_path]);