<?php
/**
 * Qarota — post video upload handler.
 */

/**
 * Handle a video upload. Validates mime + size, saves file,
 * and generates a thumbnail using ffmpeg if available.
 *
 * @param  array  $file      Entry from $_FILES['video']
 * @param  int    $duration  Duration in seconds (from client)
 * @return array{path:string, thumb:string|null, duration:int, mime:string, absolute_path:string, thumb_absolute:string|null}
 * @throws RuntimeException on any failure
 */
function handle_video_upload(array $file, int $duration): array
{
    $projectRoot = dirname(__DIR__);

    $maxBytes   = 25 * 1024 * 1024;   // 25 MB
    $maxSeconds = 60;
    $allowedMimes = [
        'video/mp4',
        'video/webm',
        'video/quicktime',
    ];
    $extMap = [
        'video/mp4'       => 'mp4',
        'video/webm'      => 'webm',
        'video/quicktime' => 'mov',
    ];

    if (!isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed.');
    }
    if ($file['size'] > $maxBytes) {
        throw new RuntimeException('Video is too large (max 25 MB).');
    }
    if ($duration < 1 || $duration > $maxSeconds) {
        throw new RuntimeException('Video must be 1 to 60 seconds.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowedMimes, true)) {
        throw new RuntimeException('Unsupported video format. Use MP4, WebM, or MOV.');
    }

    $ext    = $extMap[$mime];
    $subdir = date('Y/m');
    $dir    = $projectRoot . '/uploads/posts_video/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create upload directory.');
    }

    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $full = $dir . '/' . $name;
    $rel  = 'uploads/posts_video/' . $subdir . '/' . $name;

    if (!move_uploaded_file($file['tmp_name'], $full)) {
        throw new RuntimeException('Could not save video.');
    }
    @chmod($full, 0640);

    // Best-effort thumbnail with ffmpeg
    $thumbRel  = null;
    $thumbFull = null;

    if (function_exists('shell_exec')) {
        $thumbName = pathinfo($name, PATHINFO_FILENAME) . '_thumb.jpg';
        $thumbFull = $dir . '/' . $thumbName;
        $thumbRel  = 'uploads/posts_video/' . $subdir . '/' . $thumbName;

        $cmd = 'ffmpeg -i ' . escapeshellarg($full)
             . ' -ss 00:00:00.5 -vframes 1 -vf "scale=800:-2" '
             . escapeshellarg($thumbFull) . ' 2>&1';

        @shell_exec($cmd);

        if (!is_file($thumbFull)) {
            $thumbRel  = null;
            $thumbFull = null;
        } else {
            @chmod($thumbFull, 0644);
        }
    }

    return [
        'path'           => $rel,
        'thumb'          => $thumbRel,
        'duration'       => $duration,
        'mime'           => $mime,
        'absolute_path'  => $full,
        'thumb_absolute' => $thumbFull,
    ];
}

/**
 * Move a video (and its thumbnail) to private_posts/ when visibility changes.
 *
 * @param  array $v  Result from handle_video_upload(), or ['path'=>..., 'thumb'=>...]
 * @return array     Same shape, with updated paths
 */
function move_post_video_to_private(array $v): array
{
    $projectRoot = dirname(__DIR__);
    $targetDir   = $projectRoot . '/uploads/private_posts';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0750, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Could not create private video directory.');
    }

    $newPath  = $v['path']  ?? null;
    $newThumb = $v['thumb'] ?? null;

    if (!empty($v['path'])) {
        $src = realpath($projectRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $v['path']));
        if ($src && is_file($src)) {
            $ext  = pathinfo($src, PATHINFO_EXTENSION);
            $name = bin2hex(random_bytes(16)) . '.' . $ext;
            $dest = $targetDir . '/' . $name;
            if (rename($src, $dest)) {
                @chmod($dest, 0640);
                $newPath = 'uploads/private_posts/' . $name;
            }
        }
    }

    if (!empty($v['thumb'])) {
        $src = realpath($projectRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $v['thumb']));
        if ($src && is_file($src)) {
            $name = bin2hex(random_bytes(16)) . '_thumb.jpg';
            $dest = $targetDir . '/' . $name;
            if (rename($src, $dest)) {
                @chmod($dest, 0644);
                $newThumb = 'uploads/private_posts/' . $name;
            }
        }
    }

    $v['path']  = $newPath;
    $v['thumb'] = $newThumb;
    return $v;
}