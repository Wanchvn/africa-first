<?php
require_once __DIR__ . '/../includes/bootstrap.php';
ini_set('display_errors', '0');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/posts.php';
require_once __DIR__ . '/../includes/dating.php';
require_once __DIR__ . '/../includes/messages.php';
require_once __DIR__ . '/../includes/blocks.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/media_response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    api_error(405, 'Method not allowed.');
}

$user = api_require_auth($pdo);
$viewerId = (int)$user['id'];
$kind = (string)($_GET['kind'] ?? '');
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0 || !in_array($kind, ['post', 'message', 'dating_photo', 'verification'], true)) {
    api_error(400, 'Invalid media request.');
}

try {
    if ($kind === 'verification') {
        if ((int)($user['is_admin'] ?? 0) !== 1) {
            api_error(403, 'Forbidden.');
        }

        $slot = ($_GET['slot'] ?? '') === 'back' ? 'id_back_path' : 'id_front_path';
        $stmt = $pdo->prepare("SELECT {$slot} AS media_path FROM verification_applications WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $relativePath = $stmt->fetchColumn();
        $storageRoot = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'verifications');
        $absolutePath = $relativePath
            ? realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$relativePath))
            : false;
        if (
            $storageRoot === false
            || $absolutePath === false
            || !str_starts_with(strtolower($absolutePath), strtolower($storageRoot . DIRECTORY_SEPARATOR))
            || !is_file($absolutePath)
            || !is_readable($absolutePath)
        ) {
            api_error(404, 'Media not found.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($absolutePath);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            api_error(404, 'Media not found.');
        }
        media_send_file($absolutePath, $mime, false);
    }

    if ($kind === 'post') {
        $stmt = $pdo->prepare("
            SELECT id, user_id, visibility, media_type, media_path, media_mime
            FROM posts
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$post || empty($post['media_path'])) {
            api_error(404, 'Media not found.');
        }

        $canViewPost = can_view_post($pdo, $post, $viewerId);
        if (
            !$canViewPost
            && ($post['visibility'] ?? 'public') !== 'private'
            && (int)($user['is_admin'] ?? 0) === 1
        ) {
            $canViewPost = true;
        }
        if (!$canViewPost) {
            api_error(404, 'Media not found.');
        }

        $extension = strtolower(pathinfo((string)$post['media_path'], PATHINFO_EXTENSION));
        $mimeByExtension = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'webm' => $post['media_type'] === 'voice' ? 'audio/webm' : 'video/webm',
            'mov' => 'video/quicktime',
            'm4v' => 'video/mp4',
            'ogg' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'aac' => 'audio/aac',
        ];
        $mediaType = (string)$post['media_type'];
        $isImage = in_array($mediaType, ['image', 'photo'], true);
        $isVideo = $mediaType === 'video';
        $isVoice = $mediaType === 'voice';
        if (!$isImage && !$isVideo && !$isVoice) {
            api_error(404, 'Media not found.');
        }

        $pathPattern = $isImage
            ? '#^uploads/(?:private_posts/)?[a-f0-9]{32}\.(?:jpg|jpeg|png|gif|webp)$#i'
            : ($isVideo
                ? '#^uploads/(?:posts_video/\d{4}/\d{2}|private_posts)/[a-f0-9]{32}\.(?:mp4|webm|mov|m4v)$#i'
                : '#^uploads/(?:voice/\d{4}/\d{2}|private_posts)/[a-f0-9]{32}\.(?:webm|ogg|m4a|mp3|wav|aac)$#i');
        $subdirectory = $isImage ? '' : (($isVideo && str_starts_with((string)$post['media_path'], 'uploads/posts_video/'))
            ? 'posts_video'
            : (($isVoice && str_starts_with((string)$post['media_path'], 'uploads/voice/')) ? 'voice' : ''));
        $path = media_resolve_upload((string)$post['media_path'], $pathPattern, $subdirectory);
        $mime = $mimeByExtension[$extension] ?? '';
        if ($mime === '') {
            api_error(404, 'Media not found.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($path);
        if ($isImage) {
            if (!in_array($detectedMime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                api_error(404, 'Media not found.');
            }
            $mime = $detectedMime;
        } elseif (!empty($post['media_mime']) && in_array($post['media_mime'], [
            'video/mp4', 'video/webm', 'video/quicktime', 'video/x-m4v',
            'audio/webm', 'audio/ogg', 'audio/mp4', 'audio/mpeg', 'audio/wav', 'audio/aac',
        ], true)) {
            $mime = (string)$post['media_mime'];
        }

        media_send_file($path, $mime, $isVideo || $isVoice);
    }

    if ($kind === 'dating_photo') {
        $stmt = $pdo->prepare("
            SELECT dp.user_id, dp.path, u.is_banned
            FROM dating_photos dp
            INNER JOIN users u ON u.id = dp.user_id
            WHERE dp.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $photo = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$photo) {
            api_error(404, 'Media not found.');
        }

        $ownerId = (int)$photo['user_id'];
        $isOwner = $ownerId === $viewerId;
        if ((!$isOwner && (!is_dating_active($pdo, $viewerId) || (int)$photo['is_banned'] === 1))
            || (!$isOwner && is_blocked_either($pdo, $viewerId, $ownerId))) {
            api_error(404, 'Media not found.');
        }

        $path = media_resolve_upload(
            (string)$photo['path'],
            '#^uploads/dating_photos/' . $ownerId . '/[a-f0-9]{32}\.(?:jpg|jpeg|png|webp)$#i',
            'dating_photos/' . $ownerId
        );
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            api_error(404, 'Media not found.');
        }
        media_send_file($path, $mime, false);
    }

    $stmt = $pdo->prepare("
        SELECT id, conversation_id, message_type, body, image_path, video_path, voice_path, deleted_at
        FROM messages
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);
    if (
        !$message
        || $message['deleted_at'] !== null
        || !user_in_conversation($pdo, (int)$message['conversation_id'], $viewerId)
    ) {
        api_error(404, 'Media not found.');
    }

    $messageType = (string)$message['message_type'];
    if ($messageType === 'image') {
        $relativePath = (string)($message['image_path'] ?: $message['body']);
        $pattern = '#^uploads/(?:messages/[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|gif|webp)|dm_images/[a-f0-9]{32}\.(?:jpg|jpeg|png|gif|webp)\.enc)$#i';
        $subdirectory = str_starts_with($relativePath, 'uploads/dm_images/') ? 'dm_images' : 'messages';
        $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
        $supportsRanges = false;
    } elseif ($messageType === 'video') {
        $relativePath = (string)($message['video_path'] ?: $message['body']);
        $pattern = '#^uploads/dm_videos/[a-f0-9]{32}\.(?:mp4|webm|mov|mkv)\.enc$#i';
        $subdirectory = 'dm_videos';
        $mimeMap = ['mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'mkv' => 'video/x-matroska'];
        $supportsRanges = true;
    } elseif ($messageType === 'voice') {
        $relativePath = (string)($message['voice_path'] ?: $message['body']);
        $pattern = '#^uploads/(?:messages/[A-Za-z0-9_-]+\.m4a|voice/(?:\d{4}/\d{2}/)?[a-f0-9]{32}\.(?:webm|ogg|m4a|mp3|wav|aac)\.enc)$#i';
        $subdirectory = str_starts_with($relativePath, 'uploads/voice/') ? 'voice' : 'messages';
        $mimeMap = ['webm' => 'audio/webm', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'aac' => 'audio/aac'];
        $supportsRanges = true;
    } else {
        api_error(404, 'Media not found.');
    }

    $path = media_resolve_upload($relativePath, $pattern, $subdirectory);
    $isEncrypted = str_ends_with(strtolower($relativePath), '.enc');
    $extension = strtolower(pathinfo($isEncrypted ? substr($relativePath, 0, -4) : $relativePath, PATHINFO_EXTENSION));
    $mime = $mimeMap[$extension] ?? '';
    if ($mime === '') {
        api_error(404, 'Media not found.');
    }

    if ($isEncrypted) {
        $blob = file_get_contents($path);
        if ($blob === false) {
            api_error(500, 'Could not read media.');
        }
        try {
            $bytes = crypto_decrypt_blob($blob);
        } catch (Throwable $e) {
            error_log('API media decryption failed: ' . get_class($e));
            api_error(500, 'Could not read media.');
        }
        if (!is_string($bytes) || $bytes === '') {
            api_error(404, 'Media not found.');
        }
        media_send_bytes($bytes, $mime, $supportsRanges);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo->file($path);
    if ($messageType === 'image' && !in_array($detectedMime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        api_error(404, 'Media not found.');
    }
    media_send_file($path, $mime, $supportsRanges);
} catch (Throwable $e) {
    error_log('API media request failed: ' . get_class($e));
    api_error(500, 'Could not serve media.');
}
