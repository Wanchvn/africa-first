<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/language.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error(405, 'Method not allowed.');
}

$user = api_require_admin($pdo);
$user_id = (int)$user['id'];

try {
    rate_limit_enforce($pdo, client_ip(), 'api_post', 10, 3600); // Max 10 posts per hour
} catch (Throwable $e) {
    api_error(429, 'Too many posts. Please wait before posting again.');
}

$content = trim((string)($_POST['content'] ?? ''));
$media_path = null;
$media_type = 'text';
$media_mime = null;

if (mb_strlen($content) > 500) {
    api_error(400, 'Post content must be 500 characters or less.');
}

// Handle Image Upload
if (!empty($_FILES['image']['name']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $file = $_FILES['image'];
    $max_size = 5 * 1024 * 1024; // 5MB

    if ($file['size'] > $max_size) {
        api_error(400, 'Image must be smaller than 5 MB.');
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

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $media_directory = 'uploads/'; 
    $destination = __DIR__ . '/../' . $media_directory . $filename;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        $media_path = $media_directory . $filename;
        $media_type = 'image';
        $media_mime = $mime;
    } else {
        api_error(500, 'Could not save image.');
    }
}

if ($content === '' && $media_path === null) {
    api_error(400, 'Please write something or attach an image.');
}

$stmt = $pdo->prepare("SELECT languages FROM users WHERE id = :id");
$stmt->execute([':id' => $user_id]);
$user_lang = $stmt->fetchColumn();
$post_lang = detect_post_language($content, $user_lang);

$stmt = $pdo->prepare(
    "INSERT INTO posts (user_id, content, lang, media_path, media_type, media_mime, visibility)
     VALUES (:user_id, :content, :lang, :media, :mtype, :mmime, 'public')"
);
$stmt->execute([
    ':user_id' => $user_id,
    ':content' => $content,
    ':lang'    => $post_lang,
    ':media'   => $media_path,
    ':mtype'   => $media_type,
    ':mmime'   => $media_mime,
]);

$new_post_id = (int)$pdo->lastInsertId();

api_ok([
    'post_id' => $new_post_id,
    'message' => 'Post published successfully.'
]);