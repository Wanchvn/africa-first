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

// ============================================================
// BAN ENFORCEMENT
// ------------------------------------------------------------
// Banned users get redirected to appeal.php from every page.
// Pages that MUST remain accessible: appeal.php, logout.php,
// login.php, register.php.
//
// We query fresh every request (one indexed lookup, ~0.2ms) so
// a ban takes effect immediately — no session cache to invalidate.
// ============================================================
if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {

    $ban_allowed_scripts = [
        'appeal.php',
        'logout.php',
        'login.php',
        'register.php',
    ];

    $current_script = basename($_SERVER['PHP_SELF'] ?? '');

    if (!in_array($current_script, $ban_allowed_scripts, true)) {

        if (!isset($pdo)) {
            $db_path = __DIR__ . '/../config/db.php';
            if (is_file($db_path)) {
                require $db_path;
            }
        }

        $is_banned = false;
        if (isset($pdo)) {
            try {
                $stmt = $pdo->prepare("SELECT is_banned FROM users WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => (int)$_SESSION['user_id']]);
                $is_banned = (int)$stmt->fetchColumn() === 1;
            } catch (Throwable $e) {
                // Fail open — never lock everyone out over a DB hiccup
                $is_banned = false;
            }
        }

        if ($is_banned) {
            if (!headers_sent()) {
                header('Location: appeal.php');
            } else {
                echo '<script>window.location.href="appeal.php";</script>';
            }
            exit;
        }
    }
}