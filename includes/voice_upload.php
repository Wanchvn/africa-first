<?php
/**
 * Handle a voice note upload.
 *
 * @return array{path:string, duration:int, mime:string, absolute_path:string}
 * @throws RuntimeException on any failure
 */
function handle_voice_upload(array $file, int $durationSeconds, ?int $postId = null): array
{
    $project_root = dirname(__DIR__);

    // Limits
    $max_bytes   = 5 * 1024 * 1024;   // 5 MB
    $max_seconds = 180;                // 3 minutes

    // Allowed audio MIME types
    $allowed_mimes = [
        'audio/webm',
        'audio/ogg',
        'audio/mp4',
        'audio/mpeg',
        'audio/wav',
        'audio/x-wav',
        'audio/x-m4a',
        'audio/m4a',
        'audio/aac',
    ];

    // Mime → extension
    $ext_map = [
        'audio/webm'   => 'webm',
        'audio/ogg'    => 'ogg',
        'audio/mp4'    => 'm4a',
        'audio/x-m4a'  => 'm4a',
        'audio/m4a'    => 'm4a',
        'audio/mpeg'   => 'mp3',
        'audio/wav'    => 'wav',
        'audio/x-wav'  => 'wav',
        'audio/aac'    => 'aac',
    ];

    // 1. Basic upload check
    if (!isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed.');
    }

    if ($file['size'] > $max_bytes) {
        throw new RuntimeException('Voice note is too large (max 5 MB).');
    }

    if ($durationSeconds < 1 || $durationSeconds > $max_seconds) {
        throw new RuntimeException('Voice note must be between 1 second and 3 minutes.');
    }

    // 2. Verify it's really audio — never trust the browser
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);

    // Fallback for browsers whose blobs finfo can't identify
    if ($mime === 'application/octet-stream' && !empty($file['name'])) {
        $client_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $ext_to_mime = [
            'webm' => 'audio/webm',
            'ogg'  => 'audio/ogg',
            'm4a'  => 'audio/mp4',
            'mp3'  => 'audio/mpeg',
            'wav'  => 'audio/wav',
            'aac'  => 'audio/aac',
        ];
        if (isset($ext_to_mime[$client_ext])) {
            $mime = $ext_to_mime[$client_ext];
        }
    }

    // Absolute last resort: trust the browser-reported MIME
    if (!in_array($mime, $allowed_mimes, true) && !empty($file['type'])) {
        $client_mime = strtolower(trim($file['type']));
        if (in_array($client_mime, $allowed_mimes, true)) {
            $mime = $client_mime;
        }
    }

    if (!in_array($mime, $allowed_mimes, true)) {
        throw new RuntimeException('Unsupported audio format (' . $mime . ').');
    }

    // 3. Extension from detected mime, not from user input
    $ext = $ext_map[$mime] ?? 'bin';

    // 4. Target directory: uploads/voice/YYYY/MM/
    $subdir = date('Y/m');
    $dir    = $project_root . '/uploads/voice/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create upload directory.');
    }

    // 5. Safe, collision-proof filename
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $full = $dir . '/' . $name;
    $rel  = 'uploads/voice/' . $subdir . '/' . $name;

    if (!move_uploaded_file($file['tmp_name'], $full)) {
        throw new RuntimeException('Could not save voice note.');
    }
    @chmod($full, 0644);

    return [
        'path'          => $rel,
        'duration'      => $durationSeconds,
        'mime'          => $mime,
        'absolute_path' => $full,
    ];
}