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

    // ============================================================
    // BAN ENFORCEMENT
    // ------------------------------------------------------------
    // Banned users are redirected to appeal.php from every page.
    // Runs only for logged-in users, on pages that aren't exempt.
    // Fresh DB query each request (~0.2ms on indexed PK) so bans
    // take effect immediately — no cache to invalidate.
    // Fails open: DB errors never lock everyone out.
    // ============================================================
    enforce_ban_redirect();

    // ============================================================
    // SESSION TRACKING
    // ------------------------------------------------------------
    // Registers each login in user_sessions and enforces revocation.
    // A revoked session is destroyed on its next request.
    // ============================================================
    enforce_session_tracking();
}

/**
 * Load the DB (if needed) and run session tracking checks.
 */
function enforce_session_tracking() {
    if (empty($_SESSION['user_id'])) {
        return;
    }

    // Exempt pages that must work even when a session is revoked
    $exempt = ['logout.php', 'login.php', 'register.php', 'login_2fa.php'];
    $current_script = basename($_SERVER['PHP_SELF'] ?? '');
    if (in_array($current_script, $exempt, true)) {
        return;
    }

    try {
        if (!isset($GLOBALS['pdo'])) {
            $db_path = __DIR__ . '/../config/db.php';
            if (!is_file($db_path)) {
                return;
            }
            $pdo_check = (static function () use ($db_path) {
                require $db_path;
                return $pdo ?? null;
            })();
        } else {
            $pdo_check = $GLOBALS['pdo'];
        }

        if (!$pdo_check instanceof PDO) {
            return;   // fail open
        }

        require_once __DIR__ . '/sessions_tracker.php';

        // Register on first request after login
        if (empty($_SESSION['_session_registered'])) {
            register_current_session($pdo_check, (int)$_SESSION['user_id']);
            $_SESSION['_session_registered'] = true;
        }

        // Enforce revocation on every request
        enforce_session_validity($pdo_check, (int)$_SESSION['user_id']);
    } catch (Throwable $e) {
        // Fail open — never lock everyone out over a tracking bug
        error_log('session tracking error: ' . $e->getMessage());
    }
}

/**
 * If the current logged-in user is banned, redirect them to appeal.php.
 * Skips exempt pages and unauthenticated users.
 */
function enforce_ban_redirect() {
    if (empty($_SESSION['user_id'])) {
        return;
    }

    $allowed_scripts = [
        'appeal.php',
        'logout.php',
        'login.php',
        'register.php',
    ];

    $current_script = basename($_SERVER['PHP_SELF'] ?? '');
    if (in_array($current_script, $allowed_scripts, true)) {
        return;
    }

    // Build a fresh DB connection — do NOT rely on $GLOBALS['pdo']
    // being set, because config/db.php may not have been loaded yet.
    try {
        $db_path = __DIR__ . '/../config/db.php';
        if (!is_file($db_path)) {
            return;   // no DB config, can't check
        }

        // config/db.php is expected to create a top-level $pdo.
        // Include it in an isolated scope so we can capture $pdo safely.
        $pdo_check = (static function () use ($db_path) {
            require $db_path;
            return $pdo ?? null;
        })();

        if (!$pdo_check instanceof PDO) {
            return;   // couldn't get a PDO — fail open
        }

        $stmt = $pdo_check->prepare("SELECT is_banned FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => (int)$_SESSION['user_id']]);
        $is_banned = (int)$stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        return;   // fail open
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

    // Force re-registration of the session under its new ID
    unset($_SESSION['_session_registered']);
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