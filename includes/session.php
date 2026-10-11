<?php
require_once __DIR__ . '/proxy.php';

/**
 * Start the session with hardened cookie settings.
 * Call this INSTEAD of session_start() at the top of every page.
 */
function start_secure_session() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Detect HTTPS (works on localhost, direct HTTPS, and trusted reverse proxies)
    $is_https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? 0) == 443)
        || (
            request_is_from_trusted_proxy()
            && strtolower(trim($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        )
    );

    // Configure session cookie BEFORE session_start()
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // Hardening at the session level
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', 1800);

    // Belt-and-braces cookie hardening
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', $is_https ? '1' : '0');

    // Stronger session IDs
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');

    session_start();

    // ---- Idle timeout ----
    $idle_limit = 1800;
    $absolute_limit = 43200;
    $now = time();

    if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > $idle_limit) {
        session_unset();
        session_regenerate_id(true);
        $_SESSION['login_message'] = 'You were logged out due to inactivity. Please log in again.';
        $_SESSION['last_activity'] = $now;
        $_SESSION['session_started'] = $now;
        return;
    }

    if (isset($_SESSION['session_started']) && ($now - $_SESSION['session_started']) > $absolute_limit) {
        session_unset();
        session_regenerate_id(true);
        $_SESSION['login_message'] = 'Your session expired. Please log in again.';
        $_SESSION['last_activity'] = $now;
        $_SESSION['session_started'] = $now;
        return;
    }

    $_SESSION['last_activity'] = $now;
    if (!isset($_SESSION['session_started'])) {
        $_SESSION['session_started'] = $now;
    }
}
/**
 * Call immediately after a successful login.
 * Regenerates the session ID to prevent session fixation.
 */
function regenerate_session_on_login() {
    session_regenerate_id(true);   // true = delete old session file
    $_SESSION['session_started'] = time();
    $_SESSION['last_activity'] = time();

    // Rotate CSRF token on login
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Destroy the session completely.
 */
function destroy_session() {
    $_SESSION = [];

    // Delete the session cookie
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}