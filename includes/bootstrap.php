<?php
/**
 * Qarota — global bootstrap.
 * Include this at the very top of every entry-point PHP file,
 * BEFORE any other code (including session start).
 */

// ---- Error handling: safe in production, verbose in dev ----
$is_local = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', '::1'], true)
         || (($_SERVER['SERVER_NAME'] ?? '') === 'localhost');

if ($is_local) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);

    $log_dir = __DIR__ . '/../logs';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0775, true);
    }
    ini_set('error_log', $log_dir . '/php-errors.log');
}

// ---- Default timezone ----
date_default_timezone_set('Africa/Accra');

// ---- Security headers as a fallback if .htaccess is bypassed ----
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}
