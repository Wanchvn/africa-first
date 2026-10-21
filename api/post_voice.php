<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/language.php';
require_once __DIR__ . '/../includes/voice_upload.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error(405, 'Method not allowed.');

$user = api_require_admin($pdo);
$user_id = (int)$user['id'];

try {
    rate_limit_enforce($pdo, client_ip(), 'api_post', 10, 3600);
} catch (Throwable $e) {
    api_error(429, 'Too many posts. Slow down.');
}

if (empty($_FILES['voice']['name']) || ($_FILES['voice']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    api_error(400, 'No voice file uploaded.');
}

$duration = (int)($_POST['duration'] ?? 0);
$content = trim($_POST['content'] ?? '');

try {
    $v = handle_voice_upload($_FILES['voice'], $duration);
    $media_path = $v['path'];
    $media_type = 'voice';
    $media_duration = $v['duration'];
    $media_mime = $v['mime'];

    $stmt = $pdo->prepare("SELECT languages FROM users WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $user_lang = $stmt->fetchColumn();
    $post_lang = detect_post_language($content, $user_lang);

    $stmt = $pdo->prepare(
        "INSERT INTO posts (user_id, content, lang, media_path, media_type, media_duration, media_mime, visibility)
         VALUES (:user_id, :content, :lang, :media, :mtype, :mdur, :mmime, 'public')"
    );
    $stmt->execute([
        ':user_id' => $user_id,
        ':content' => $content,
        ':lang'    => $post_lang,
        ':media'   => $media_path,
        ':mtype'   => $media_type,
        ':mdur'    => $media_duration,
        ':mmime'   => $media_mime,
    ]);

    api_ok(['post_id' => $pdo->lastInsertId(), 'message' => 'Voice note posted.']);
} catch (Throwable $e) {
    api_error(400, $e->getMessage());
}