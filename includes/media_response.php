<?php

function media_send_content(string $mimeType, int $size, bool $supportsRanges, callable $write): void
{
    $start = 0;
    $end = max(0, $size - 1);
    $status = 200;
    $range = $_SERVER['HTTP_RANGE'] ?? '';

    if ($supportsRanges && $range !== '') {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/D', trim($range), $matches)
            || ($matches[1] === '' && $matches[2] === '')
            || $size <= 0
        ) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }

        if ($matches[1] === '') {
            $suffixLength = (int)$matches[2];
            if ($suffixLength <= 0) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            $start = max(0, $size - $suffixLength);
        } else {
            $start = (int)$matches[1];
            if ($matches[2] !== '') {
                $end = (int)$matches[2];
            }
        }

        if ($start >= $size || $end < $start) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }

        $end = min($end, $size - 1);
        $status = 206;
    }

    $length = $size === 0 ? 0 : $end - $start + 1;
    http_response_code($status);
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . $length);
    header('Content-Disposition: inline');
    header('Cache-Control: private, no-store');
    header('Vary: Authorization');
    header('X-Content-Type-Options: nosniff');
    if ($supportsRanges) {
        header('Accept-Ranges: bytes');
    }
    if ($status === 206) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' || $length === 0) {
        exit;
    }

    $write($start, $length);
    exit;
}

function media_send_file(string $path, string $mimeType, bool $supportsRanges): void
{
    if (!is_file($path) || !is_readable($path)) {
        api_error(404, 'Media not found.');
    }

    $file = fopen($path, 'rb');
    if ($file === false) {
        api_error(500, 'Could not read media.');
    }

    media_send_content($mimeType, (int)filesize($path), $supportsRanges, static function (int $start, int $length) use ($file): void {
        if ($start > 0 && fseek($file, $start) !== 0) {
            fclose($file);
            return;
        }

        while ($length > 0 && !feof($file)) {
            $chunk = fread($file, min(8192, $length));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $length -= strlen($chunk);
            flush();
        }
        fclose($file);
    });
}

function media_send_bytes(string $bytes, string $mimeType, bool $supportsRanges): void
{
    media_send_content($mimeType, strlen($bytes), $supportsRanges, static function (int $start, int $length) use ($bytes): void {
        echo substr($bytes, $start, $length);
    });
}

function media_resolve_upload(string $relativePath, string $pattern, string $subdirectory): string
{
    if (!preg_match($pattern, $relativePath)) {
        api_error(404, 'Media not found.');
    }

    $projectRoot = dirname(__DIR__);
    $allowedRoot = realpath($projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $subdirectory);
    $absolutePath = realpath($projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    if (
        $allowedRoot === false
        || $absolutePath === false
        || !str_starts_with(strtolower($absolutePath), strtolower($allowedRoot . DIRECTORY_SEPARATOR))
        || !is_file($absolutePath)
        || !is_readable($absolutePath)
    ) {
        api_error(404, 'Media not found.');
    }

    return $absolutePath;
}
