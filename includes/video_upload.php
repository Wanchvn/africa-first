<?php
require_once __DIR__ . '/video_compress.php';

/**
 * Qarota — post video upload handler.
 */

/**
 * Handle a video upload. Validates mime + size, saves file,
 * compresses it (best-effort), and generates a thumbnail via ffmpeg.
 *
 * @param  array  $file      Entry from $_FILES['video']
 * @param  int    $duration  Client-reported duration (seconds); used as fallback
 * @return array{path:string, thumb:string|null, duration:int, mime:string,
 *               width:int|null, height:int|null,
 *               absolute_path:string, thumb_absolute:string|null}
 * @throws RuntimeException on any failure
 */
function handle_video_upload(array $file, int $duration): array
{
    // 650 MB uploads + compression can take several minutes
    @set_time_limit(600);

    $projectRoot = dirname(__DIR__);

    $maxBytes   = 650 * 1024 * 1024;   // 650 MB
    $maxSeconds = 1200;                // 10 minutes hard cap

    $allowedMimes = [
        'video/mp4',
        'video/webm',
        'video/quicktime',
        'video/x-m4v',
    ];
    $extMap = [
        'video/mp4'       => 'mp4',
        'video/webm'      => 'webm',
        'video/quicktime' => 'mov',
        'video/x-m4v'     => 'm4v',
    ];

    if (!isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed.');
    }
    if ($file['size'] > $maxBytes) {
        throw new RuntimeException('That video is too large. Try a shorter clip.');
    }

    // Client-reported duration is a hint, not ground truth: some browsers
    // can't decode .mov from iPhones and probe 0. We'll confirm server-side.
    $clientDuration = max(0, (int)$duration);
    if ($clientDuration > $maxSeconds) {
        throw new RuntimeException('Videos can be up to 10 minutes long.');
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

    // ---- Compress (best-effort) ----
    // compress_video() may overwrite $full in place, or return a new path.
    // Trust the return value if provided; otherwise assume in-place.
    try {
        $compressResult = compress_video($full);

        if (!empty($compressResult['path']) && is_file($compressResult['path'])) {
            // compress_video moved the result somewhere else — update our refs.
            if ($compressResult['path'] !== $full) {
                // Delete the original uncompressed file if it still exists.
                if (is_file($full)) @unlink($full);

                $full = $compressResult['path'];
                // Recompute the relative path from the project root.
                $rel = ltrim(str_replace(
                    DIRECTORY_SEPARATOR,
                    '/',
                    substr($full, strlen($projectRoot))
                ), '/');
            }
        }
        if (!empty($compressResult['compressed'])) {
            error_log(sprintf(
                'Video compressed: %s (%d KB → %d KB, saved %.1f%%)',
                basename($full),
                (int)round($compressResult['before'] / 1024),
                (int)round($compressResult['after']  / 1024),
                (1 - ($compressResult['after'] / max(1, $compressResult['before']))) * 100
            ));
        }
    } catch (Throwable $e) {
        // Compression is best-effort. Log and continue with the original.
        error_log('compress_video failed: ' . $e->getMessage());
    }

    // ---- Probe duration + dimensions server-side (ffprobe) ----
    // This is our source of truth. Client value is only a fallback.
    $probed = probe_video_metadata($full);
    $finalDuration = $probed['duration'] > 0
        ? $probed['duration']
        : $clientDuration;

    if ($finalDuration > $maxSeconds) {
        @unlink($full);
        throw new RuntimeException('Videos can be up to 10 minutes long.');
    }
    // If we truly can't determine duration, allow but record 0 so it
    // doesn't break playback; UI will show "--:--" until it plays.
    $finalDuration = max(0, (int)$finalDuration);

    // ---- Thumbnail (best-effort) ----
    $thumbRel  = null;
    $thumbFull = null;

    if (function_exists('shell_exec')) {
        $thumbName = pathinfo($name, PATHINFO_FILENAME) . '_thumb.jpg';
        $thumbFull = $dir . '/' . $thumbName;
        $thumbRel  = 'uploads/posts_video/' . $subdir . '/' . $thumbName;

        // -ss BEFORE -i = fast keyframe seek (much faster on long videos).
        // -frames:v 1 = one frame. -q:v 3 = decent JPEG quality.
        $cmd = 'ffmpeg -y'
             . ' -ss 00:00:01'
             . ' -i ' . escapeshellarg($full)
             . ' -frames:v 1'
             . ' -vf "scale=800:-2"'
             . ' -q:v 3'
             . ' ' . escapeshellarg($thumbFull)
             . ' 2>&1';

        @shell_exec($cmd);

        if (!is_file($thumbFull) || filesize($thumbFull) === 0) {
            if (is_file($thumbFull)) @unlink($thumbFull);
            $thumbRel  = null;
            $thumbFull = null;
        } else {
            @chmod($thumbFull, 0644);
        }
    }

    // ---- Cleanup on failure ----
    // If we got this far without a thumb and something's off, it's still OK —
    // the video plays fine without a poster. Only clean up on hard failures,
    // which we've already handled above (duration too long).

    return [
        'path'           => $rel,
        'thumb'          => $thumbRel,
        'duration'       => $finalDuration,
        'mime'           => $mime,
        'width'          => $probed['width']  ?: null,
        'height'         => $probed['height'] ?: null,
        'absolute_path'  => $full,
        'thumb_absolute' => $thumbFull,
    ];
}

/**
 * Probe a video's duration and dimensions with ffprobe. Best-effort.
 *
 * @return array{duration:int, width:int, height:int}
 */
function probe_video_metadata(string $path): array
{
    $out = ['duration' => 0, 'width' => 0, 'height' => 0];

    if (!function_exists('shell_exec')) return $out;
    if (!is_file($path)) return $out;

    // Ask ffprobe for JSON so we don't have to parse key=value lines.
    $cmd = 'ffprobe -v error -show_entries'
         . ' format=duration:stream=width,height,codec_type'
         . ' -of json '
         . escapeshellarg($path)
         . ' 2>/dev/null';

    $json = @shell_exec($cmd);
    if (!$json) return $out;

    $data = json_decode($json, true);
    if (!is_array($data)) return $out;

    if (!empty($data['format']['duration'])) {
        $out['duration'] = (int)round((float)$data['format']['duration']);
    }

    // Find the first video stream and grab its dimensions.
    if (!empty($data['streams']) && is_array($data['streams'])) {
        foreach ($data['streams'] as $stream) {
            if (($stream['codec_type'] ?? '') === 'video') {
                $out['width']  = (int)($stream['width']  ?? 0);
                $out['height'] = (int)($stream['height'] ?? 0);
                break;
            }
        }
    }

    return $out;
}

/**
 * Move a video (and its thumbnail) to private_posts/ when visibility changes.
 *
 * Note: this invalidates any absolute_path / thumb_absolute values returned
 * by handle_video_upload(). Callers should not cache those across a move.
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
    // Absolute paths are now stale — clear them so no caller uses them.
    unset($v['absolute_path'], $v['thumb_absolute']);
    return $v;
}