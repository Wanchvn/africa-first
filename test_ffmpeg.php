<?php
header('Content-Type: text/plain');
require_once __DIR__ . '/includes/video_compress.php';

$ffmpeg = find_ffmpeg();

if ($ffmpeg === null) {
    echo "ffmpeg NOT FOUND\n";
    echo "Searched: C:\\ffmpeg\\bin\\, PATH, and common locations.\n";
    echo "PHP's PATH is:\n";
    echo shell_exec('echo %PATH%');
    exit;
}

echo "ffmpeg found at: {$ffmpeg}\n\n";
echo "Version check:\n";
echo shell_exec('"' . $ffmpeg . '" -version 2>&1');