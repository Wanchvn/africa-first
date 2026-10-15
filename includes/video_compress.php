<?php
/**
 * Qarota — video compression.
 *
 * Re-encodes an uploaded video to a smaller, standard MP4 file
 * using ffmpeg. Falls back to the original if compression fails
 * or produces a larger file.
 */

/**
 * Compress a video in place.
 *
 * @param  string $sourcePath  Absolute path to the video
 * @return array { path, compressed, before, after }
 */
function compress_video(string $sourcePath): array
{
    $before = @filesize($sourcePath);
    if ($before === false || $before <= 0) {
        return ['path' => $sourcePath, 'compressed' => false, 'before' => 0, 'after' => 0];
    }

    $ffmpeg = find_ffmpeg();
    if ($ffmpeg === null) {
        return ['path' => $sourcePath, 'compressed' => false, 'before' => $before, 'after' => $before];
    }

    // ---- Skip small files ----
    // Under 15 MB, re-encoding costs more time than it saves bandwidth.
    if ($before < 15 * 1024 * 1024) {
        return ['path' => $sourcePath, 'compressed' => false, 'before' => $before, 'after' => $before];
    }

    // ---- Prepare temp output in the same directory ----
    $dir     = dirname($sourcePath);
    $base    = pathinfo($sourcePath, PATHINFO_FILENAME);
    $tmpPath = $dir . '/' . $base . '_compressed.mp4';

    // ---- Choose CRF based on file size ----
    // Higher CRF = smaller file, lower quality. 23 = default, 26-30 = visibly
    // smaller but still fine for phone viewing.
    if ($before > 300 * 1024 * 1024) {
        $crf          = 30;
        $audioBitrate = '96k';
    } elseif ($before > 100 * 1024 * 1024) {
        $crf          = 29;
        $audioBitrate = '96k';
    } elseif ($before > 50 * 1024 * 1024) {
        $crf          = 28;
        $audioBitrate = '96k';
    } elseif ($before > 15 * 1024 * 1024) {
        $crf          = 27;
        $audioBitrate = '128k';
    } else {
        $crf          = 26;
        $audioBitrate = '128k';
    }

    // ---- Scale filter ----
    // Single pass: cap to 1280x720, preserve aspect ratio, ensure even dims.
    // force_divisible_by=2 requires ffmpeg >= 4.3. If your ffmpeg is older,
    // use the two-stage version in the comment below.
    $vf = "scale='min(1280,iw)':'min(720,ih)':force_original_aspect_ratio=decrease:force_divisible_by=2";

    // Fallback for ffmpeg < 4.3 (comment out the line above and use this):
    // $vf = "scale='min(1280,iw)':'min(720,ih)':force_original_aspect_ratio=decrease,scale=trunc(iw/2)*2:trunc(ih/2)*2";

    // ---- Build command ----
    // -threads 0     use all CPU cores (huge speedup on Windows)
    // -preset veryfast   fast encode, ~15% larger output than 'medium'
    // -crf N         quality/size tradeoff
    // -c:a aac       standard audio codec for MP4
    // -movflags +faststart   stream before full download
    // No -r flag: keep the source framerate. Forcing 30 duplicates frames
    // on 24fps sources and wastes CPU.
    $cmd = '"' . $ffmpeg . '"'
         . ' -i ' . escapeshellarg($sourcePath)
         . ' -c:v libx264'
         . ' -preset veryfast'
         . ' -crf ' . $crf
         . ' -vf ' . escapeshellarg($vf)
         . ' -threads 0'
         . ' -t 1200' 
         . ' -c:a aac -b:a ' . $audioBitrate
         . ' -movflags +faststart'
         . ' -y ' . escapeshellarg($tmpPath)
         . ' 2>&1';

    @shell_exec($cmd);

    // ---- Verify output ----
    if (!is_file($tmpPath) || filesize($tmpPath) === 0) {
        @unlink($tmpPath);
        error_log("Video compression failed (no output): {$sourcePath}");
        return ['path' => $sourcePath, 'compressed' => false, 'before' => $before, 'after' => $before];
    }

    $after = filesize($tmpPath);

    // ---- Only swap if meaningfully smaller ----
    // 10% headroom: don't bother swapping for tiny savings.
    if ($after >= $before * 0.90) {
        @unlink($tmpPath);
        error_log(sprintf(
            'Video compression skipped (not worth it): %s (%d KB → %d KB)',
            basename($sourcePath),
            (int)round($before / 1024),
            (int)round($after / 1024)
        ));
        return ['path' => $sourcePath, 'compressed' => false, 'before' => $before, 'after' => $before];
    }

    // ---- Replace original with compressed ----
    if (!@rename($tmpPath, $sourcePath)) {
        if (!@copy($tmpPath, $sourcePath)) {
            @unlink($tmpPath);
            error_log("Video compression failed (replace failed): {$sourcePath}");
            return ['path' => $sourcePath, 'compressed' => false, 'before' => $before, 'after' => $before];
        }
        @unlink($tmpPath);
    }

    @chmod($sourcePath, 0640);

    return [
        'path'       => $sourcePath,
        'compressed' => true,
        'before'     => $before,
        'after'      => @filesize($sourcePath) ?: $before,
    ];
}

/**
 * Locate ffmpeg — checks PATH first, then common install locations.
 */
function find_ffmpeg(): ?string
{
    static $cached = false;
    static $path = null;

    if ($cached) return $path;
    $cached = true;

    $candidates = [
        'C:\\ffmpeg\\bin\\ffmpeg.exe',
        'C:\\Program Files\\ffmpeg\\bin\\ffmpeg.exe',
        'C:\\Program Files (x86)\\ffmpeg\\bin\\ffmpeg.exe',
        'C:\\xampp\\ffmpeg\\bin\\ffmpeg.exe',
        '/usr/bin/ffmpeg',
        '/usr/local/bin/ffmpeg',
        '/opt/homebrew/bin/ffmpeg',
    ];

    foreach ($candidates as $c) {
        if (is_file($c) && is_executable($c)) {
            $path = $c;
            return $path;
        }
    }

    if (function_exists('shell_exec')) {
        $which = DIRECTORY_SEPARATOR === '\\' ? 'where ffmpeg' : 'command -v ffmpeg';
        $out = @shell_exec($which . ' 2>&1');
        if ($out && preg_match('#^([^\r\n]+)#', trim($out), $m)) {
            $found = trim($m[1]);
            if (is_file($found)) {
                $path = $found;
                return $path;
            }
        }
    }

    return null;
}