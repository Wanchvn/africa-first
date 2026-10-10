<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$message = '';

// Determine where to redirect back to (default: profile)
$redirect_to = $_POST['redirect'] ?? 'profile.php';
$allowed_redirects = ['profile.php', 'welcome.php'];
if (!in_array($redirect_to, $allowed_redirects, true)) {
    $redirect_to = 'profile.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    rate_limit_enforce($pdo, client_ip(), 'avatar_upload', 10, 3600);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['avatar']['name'])) {
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $max_size = 3 * 1024 * 1024; // 3 MB — avatars don't need to be big

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES['avatar']['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed)) {
        $message = 'Only JPG, PNG, GIF, or WebP images are allowed.';
    } elseif ($_FILES['avatar']['size'] > $max_size) {
        $message = 'Avatar must be smaller than 3 MB.';
    } elseif ($_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        $message = 'Upload failed. Try again.';
    } else {
        // Get the user's current avatar so we can delete the old file
        $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $old = $stmt->fetch();

        $ext = match($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        };
        $filename = 'avatar_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $destination = __DIR__ . '/uploads/avatars/' . $filename;

        if (move_uploaded_file($_FILES['avatar']['tmp_name'], $destination)) {
            $path = 'uploads/avatars/' . $filename;

            $stmt = $pdo->prepare("UPDATE users SET avatar = :avatar WHERE id = :id");
            $stmt->execute([':avatar' => $path, ':id' => $user_id]);

            // Delete the old avatar file (optional, saves disk space)
            if ($old && $old['avatar'] && file_exists(__DIR__ . '/' . $old['avatar'])) {
                @unlink(__DIR__ . '/' . $old['avatar']);
            }

            $message = 'Photo updated.';
        } else {
            $message = 'Could not save the file.';
        }
    }
}

// Store the message in session, then redirect back
$_SESSION['avatar_message'] = $message;
header('Location: ' . $redirect_to);
exit;