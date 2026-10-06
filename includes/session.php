<?php

/**
 * Start the session with hardened cookie settings.
 * Call this INSTEAD of session_start() at the top of every page.
 */
function start_secure_session() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Detect HTTPS (works on localhost too)
    $is_https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? 0) == 443
    );

    // Configure session cookie BEFORE session_start()
    session_set_cookie_params([
        'lifetime' => 0,           // expires when browser closes
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_https,   // HTTPS-only when on production
        'httponly' => true,        // JS cannot read the cookie
        'samesite' => 'Lax',       // blocks most CSRF, allows normal navigation
    ]);

    // Hardening at the session level
    ini_set('session.use_strict_mode', '1');  // reject uninitialized session IDs
    ini_set('session.use_only_cookies', '1'); // never use URL-based sessions
    ini_set('session.gc_maxlifetime', 1800);  // 30 min server-side garbage collection

    session_start();

    // ---- Idle timeout ----
    $idle_limit = 1800;      // 30 minutes
    $absolute_limit = 43200; // 12 hours

    $now = time();

    if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > $idle_limit) {
        // Session expired from inactivity
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['login_message'] = 'You were logged out due to inactivity. Please log in again.';
        return;
    }

    if (isset($_SESSION['session_started']) && ($now - $_SESSION['session_started']) > $absolute_limit) {
        // Absolute session lifetime exceeded
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['login_message'] = 'Your session expired. Please log in again.';
        return;
    }

    // Update last activity
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