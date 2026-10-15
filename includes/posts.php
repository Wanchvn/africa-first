<?php
require_once __DIR__ . '/blocks.php';

function can_view_post(PDO $pdo, array $post, ?int $viewerId): bool
{
    $authorId = (int)($post['user_id'] ?? 0);

    if ($viewerId !== null && $authorId === $viewerId) {
        return true;
    }
    if ($viewerId !== null && $authorId > 0 && is_blocked_either($pdo, $viewerId, $authorId)) {
        return false;
    }

    $visibility = $post['visibility'] ?? 'public';
    if ($visibility === 'public') return true;
    if ($visibility === 'private') return false;
    if ($visibility !== 'followers' || $viewerId === null || $authorId <= 0) return false;

    $stmt = $pdo->prepare(
        "SELECT 1 FROM follows
         WHERE follower_id = :viewer AND following_id = :author
         LIMIT 1"
    );
    $stmt->execute([':viewer' => $viewerId, ':author' => $authorId]);
    return (bool)$stmt->fetchColumn();
}

function post_visibility_filter(string $postAlias, string $parameterPrefix): string
{
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $postAlias)
        || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $parameterPrefix)) {
        throw new InvalidArgumentException('Invalid post visibility SQL identifier.');
    }

    $owner = ':' . $parameterPrefix . '_owner';
    $follower = ':' . $parameterPrefix . '_follower';
    $blocker = ':' . $parameterPrefix . '_blocker';
    $blocked = ':' . $parameterPrefix . '_blocked';

    return "(
        $postAlias.user_id = $owner
        OR (
            (
                $postAlias.visibility = 'public'
                OR (
                    $postAlias.visibility = 'followers'
                    AND EXISTS (
                        SELECT 1 FROM follows visibility_follow
                        WHERE visibility_follow.follower_id = $follower
                          AND visibility_follow.following_id = $postAlias.user_id
                    )
                )
            )
            AND NOT EXISTS (
                SELECT 1 FROM user_blocks visibility_block
                WHERE (visibility_block.blocker_id = $blocker
                       AND visibility_block.blocked_id = $postAlias.user_id)
                   OR (visibility_block.blocker_id = $postAlias.user_id
                       AND visibility_block.blocked_id = $blocked)
            )
        )
    )";
}

function post_visibility_params(?int $viewerId, string $parameterPrefix): array
{
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $parameterPrefix)) {
        throw new InvalidArgumentException('Invalid post visibility parameter prefix.');
    }

    $viewerId = $viewerId ?? 0;
    return [
        ':' . $parameterPrefix . '_owner' => $viewerId,
        ':' . $parameterPrefix . '_follower' => $viewerId,
        ':' . $parameterPrefix . '_blocker' => $viewerId,
        ':' . $parameterPrefix . '_blocked' => $viewerId,
    ];
}

function move_post_media_to_private(string $mediaPath): array
{
    if (preg_match('#^uploads/private_posts/[a-f0-9]{32}\.(?:jpg|jpeg|png|gif|webp)$#i', $mediaPath)) {
        return ['media_path' => $mediaPath, 'source_path' => null, 'destination_path' => null];
    }
    if (!preg_match('#^uploads/[a-f0-9]{32}\.(?:jpg|jpeg|png|gif|webp)$#i', $mediaPath)) {
        throw new RuntimeException('Post media path is not a supported upload path.');
    }

    $projectRoot = dirname(__DIR__);
    $uploadsRoot = realpath($projectRoot . DIRECTORY_SEPARATOR . 'uploads');
    $sourcePath = realpath($projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $mediaPath));
    if ($uploadsRoot === false || $sourcePath === false
        || !str_starts_with(strtolower($sourcePath), strtolower($uploadsRoot . DIRECTORY_SEPARATOR))
        || !is_file($sourcePath)) {
        throw new RuntimeException('Could not locate post media to secure it.');
    }

    $privateDirectory = $uploadsRoot . DIRECTORY_SEPARATOR . 'private_posts';
    if (!is_dir($privateDirectory) && !mkdir($privateDirectory, 0750, true) && !is_dir($privateDirectory)) {
        throw new RuntimeException('Could not create the private post media directory.');
    }

    $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $destinationPath = $privateDirectory . DIRECTORY_SEPARATOR . $filename;
    if (!rename($sourcePath, $destinationPath)) {
        throw new RuntimeException('Could not secure the post media file.');
    }

    return [
        'media_path' => 'uploads/private_posts/' . $filename,
        'source_path' => $sourcePath,
        'destination_path' => $destinationPath,
    ];
}

function move_voice_note(string $mediaPath, string $target): string
{
    $projectRoot = dirname(__DIR__);
    $uploadsRoot = realpath($projectRoot . DIRECTORY_SEPARATOR . 'uploads');
    if ($uploadsRoot === false) throw new RuntimeException('Uploads directory missing.');

    $isPublic  = (bool)preg_match('#^uploads/voice/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\.(webm|ogg|m4a|mp3|wav|aac)$#i', $mediaPath);
    $isPrivate = (bool)preg_match('#^uploads/private_posts/[a-f0-9]{32}\.(webm|ogg|m4a|mp3|wav|aac)$#i', $mediaPath);
    if (!$isPublic && !$isPrivate) return $mediaPath;
    if ($target === 'private' && $isPrivate) return $mediaPath;
    if ($target === 'public'  && $isPublic)  return $mediaPath;

    $source = realpath($projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $mediaPath));
    if ($source === false
        || !str_starts_with(strtolower($source), strtolower($uploadsRoot . DIRECTORY_SEPARATOR))
        || !is_file($source)) {
        throw new RuntimeException('Could not locate the voice note to move.');
    }

    $ext  = strtolower(pathinfo($source, PATHINFO_EXTENSION));
    $name = bin2hex(random_bytes(16)) . '.' . $ext;

    if ($target === 'private') {
        $destDir = $uploadsRoot . DIRECTORY_SEPARATOR . 'private_posts';
        $newRel  = 'uploads/private_posts/' . $name;
    } else {
        $destDir = $uploadsRoot . DIRECTORY_SEPARATOR . 'voice' . DIRECTORY_SEPARATOR
                 . date('Y') . DIRECTORY_SEPARATOR . date('m');
        $newRel  = 'uploads/voice/' . date('Y') . '/' . date('m') . '/' . $name;
    }

    if (!is_dir($destDir) && !mkdir($destDir, 0750, true) && !is_dir($destDir)) {
        throw new RuntimeException('Could not create destination directory.');
    }

    $dest = $destDir . DIRECTORY_SEPARATOR . $name;
    if (!rename($source, $dest)) {
        throw new RuntimeException('Could not move the voice note.');
    }
    @chmod($dest, 0644);
    return $newRel;
}

function update_post_visibility(PDO $pdo, int $postId, int $userId, string $newVisibility): string
{
    if (!in_array($newVisibility, ['public', 'followers', 'private'], true)) {
        throw new InvalidArgumentException('Invalid visibility.');
    }

    $stmt = $pdo->prepare("SELECT user_id, media_type, media_path, visibility FROM posts WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $postId]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) throw new RuntimeException('Post not found.');
    if ((int)$post['user_id'] !== $userId) throw new RuntimeException('You can only change your own posts.');

    $target = $newVisibility === 'private' ? 'private' : 'public';
    $newMediaPath = $post['media_path'] ?? null;

    if (($post['media_type'] ?? 'text') === 'voice' && !empty($post['media_path'])) {
        $newMediaPath = move_voice_note($post['media_path'], $target);
    }

    $stmt = $pdo->prepare(
        "UPDATE posts SET visibility = :v, media_path = :mp, edited_at = NOW() WHERE id = :id"
    );
    $stmt->execute([':v' => $newVisibility, ':mp' => $newMediaPath, ':id' => $postId]);

    return $newVisibility;
}

require_once __DIR__ . '/voice_upload.php';

function create_post(
    PDO $pdo,
    int $userId,
    string $content,
    ?array $voiceFile,
    int $voiceDuration,
    string $visibility = 'public'
): int {
    if (!in_array($visibility, ['public', 'followers', 'private'], true)) {
        $visibility = 'public';
    }

    $mediaType = 'text';
    $mediaPath = null;
    $mediaMime = null;
    $mediaDur  = null;
    $uploadedFile = null;

    if ($voiceFile && ($voiceFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $uploadedFile = handle_voice_upload($voiceFile, $voiceDuration);
        $mediaType = 'voice';
        $mediaPath = $uploadedFile['path'];
        $mediaMime = $uploadedFile['mime'];
        $mediaDur  = $uploadedFile['duration'];
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO posts
                (user_id, content, media_path, media_type, media_duration, media_mime, visibility, created_at)
             VALUES
                (:u, :c, :mp, :mt, :md, :mm, :v, NOW())'
        );
        $stmt->execute([
            ':u'  => $userId,
            ':c'  => $content,
            ':mp' => $mediaPath,
            ':mt' => $mediaType,
            ':md' => $mediaDur,
            ':mm' => $mediaMime,
            ':v'  => $visibility,
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        if ($uploadedFile && is_file($uploadedFile['absolute_path'])) {
            @unlink($uploadedFile['absolute_path']);
        }
        throw $e;
    }
}

function delete_post(PDO $pdo, int $postId, int $userId): bool
{
    $stmt = $pdo->prepare(
        'SELECT user_id, media_type, media_path, video_thumb FROM posts WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $postId]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post || (int)$post['user_id'] !== $userId) {
        return false;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM post_topics   WHERE post_id = :id')->execute([':id' => $postId]);
        $pdo->prepare('DELETE FROM post_views    WHERE post_id = :id')->execute([':id' => $postId]);
        $pdo->prepare('DELETE FROM likes         WHERE post_id = :id')->execute([':id' => $postId]);
        $pdo->prepare('DELETE FROM comments      WHERE post_id = :id')->execute([':id' => $postId]);
        $pdo->prepare('DELETE FROM bookmarks     WHERE post_id = :id')->execute([':id' => $postId]);
        $pdo->prepare('DELETE FROM notifications WHERE post_id = :id')->execute([':id' => $postId]);
        $pdo->prepare('DELETE FROM posts WHERE id = :id')->execute([':id' => $postId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Media file cleanup
    if (!empty($post['media_path'])) {
        $pattern = '#^uploads/(voice/[0-9]{4}/[0-9]{2}|posts_video/[0-9]{4}/[0-9]{2}|private_posts)/[a-f0-9]{32}\.(webm|ogg|m4a|mp3|wav|aac|jpg|jpeg|png|gif|webp|mp4|mov)$#i';
        if (preg_match($pattern, $post['media_path'])) {
            $abs = dirname(__DIR__) . '/' . $post['media_path'];
            if (is_file($abs)) @unlink($abs);
        }
    }

    // Thumbnail cleanup
    if (!empty($post['video_thumb'])) {
        $thumbPattern = '#^uploads/(posts_video/[0-9]{4}/[0-9]{2}|private_posts)/[a-f0-9]{32}_thumb\.jpg$#';
        if (preg_match($thumbPattern, $post['video_thumb'])) {
            $thumbAbs = dirname(__DIR__) . '/' . $post['video_thumb'];
            if (is_file($thumbAbs)) @unlink($thumbAbs);
        }
    }

    return true;
}