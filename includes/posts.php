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
    if ($visibility === 'public') {
        return true;
    }

    if ($visibility === 'private') {
        return false;
    }

    if ($visibility !== 'followers' || $viewerId === null || $authorId <= 0) {
        return false;
    }

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
        return [
            'media_path' => $mediaPath,
            'source_path' => null,
            'destination_path' => null,
        ];
    }

    if (!preg_match('#^uploads/[a-f0-9]{32}\.(?:jpg|jpeg|png|gif|webp)$#i', $mediaPath)) {
        throw new RuntimeException('Post media path is not a supported upload path.');
    }

    $projectRoot = dirname(__DIR__);
    $uploadsRoot = realpath($projectRoot . DIRECTORY_SEPARATOR . 'uploads');
    $sourcePath = realpath(
        $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $mediaPath)
    );
    if (
        $uploadsRoot === false
        || $sourcePath === false
        || !str_starts_with(strtolower($sourcePath), strtolower($uploadsRoot . DIRECTORY_SEPARATOR))
        || !is_file($sourcePath)
    ) {
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



require_once __DIR__ . '/voice_upload.php';

/**
 * Create a new post. Handles text-only, image-only, or voice-note posts.
 *
 * @param array|null $voiceFile  Entry from $_FILES['voice'] or null
 * @param int        $voiceDuration  Duration in seconds reported by the client
 * @return int  New post ID
 * @throws RuntimeException on failure
 */
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

    // Only process if a file was actually uploaded
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
            ':c'  => $content,       // '' is fine — NOT NULL accepts empty string
            ':mp' => $mediaPath,
            ':mt' => $mediaType,
            ':md' => $mediaDur,
            ':mm' => $mediaMime,
            ':v'  => $visibility,
        ]);

        return (int)$pdo->lastInsertId();

    } catch (Throwable $e) {
        // Clean up orphaned file if the DB insert failed
        if ($uploadedFile && is_file($uploadedFile['absolute_path'])) {
            @unlink($uploadedFile['absolute_path']);
        }
        throw $e;
    }
}

/**
 * Delete a post and its associated media file (if any).
 */
function delete_post(PDO $pdo, int $postId, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT user_id, media_path FROM posts WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $postId]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post || (int)$post['user_id'] !== $userId) {
        return false;
    }

    $pdo->prepare('DELETE FROM posts WHERE id = :id')->execute([':id' => $postId]);

    // Remove the media file if it exists and is inside uploads/voice/
    if (!empty($post['media_path'])
        && preg_match('#^uploads/voice/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\.(webm|ogg|m4a|mp3|wav)$#', $post['media_path'])
    ) {
        $abs = dirname(__DIR__) . '/' . $post['media_path'];
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    return true;
}