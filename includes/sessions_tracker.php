<?php
/**
 * Qarota — session tracking.
 *
 * Registers each login as a row in user_sessions, updates last_seen_at on
 * each request, and enforces revocation.
 */

/**
 * Register the current session. Called after login (or once per request).
 */
function register_current_session(PDO $pdo, int $userId): void
{
    $sid = session_id();
    if ($sid === '') return;

    $ip  = client_ip();
    $ua  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    $dev = parse_device_label($ua);

    // Upsert by session_id
    $stmt = $pdo->prepare("
        INSERT INTO user_sessions (id, user_id, ip_address, user_agent, device_label, last_seen_at, created_at)
        VALUES (:sid, :uid, :ip, :ua, :dev, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            user_id      = VALUES(user_id),
            ip_address   = VALUES(ip_address),
            user_agent   = VALUES(user_agent),
            device_label = VALUES(device_label),
            last_seen_at = NOW()
    ");
    $stmt->execute([
        ':sid' => $sid,
        ':uid' => $userId,
        ':ip'  => $ip,
        ':ua'  => $ua,
        ':dev' => $dev,
    ]);
}

/**
 * Check whether the current session has been revoked.
 * If revoked, destroy the session and return false.
 */
function enforce_session_validity(PDO $pdo, int $userId): bool
{
    $sid = session_id();
    if ($sid === '') return true;

    $stmt = $pdo->prepare("
        SELECT revoked_at FROM user_sessions
        WHERE id = :sid AND user_id = :uid
        LIMIT 1
    ");
    $stmt->execute([':sid' => $sid, ':uid' => $userId]);
    $revoked_at = $stmt->fetchColumn();

    if ($revoked_at !== false && $revoked_at !== null) {
        // This session was revoked — kill it
        require_once __DIR__ . '/session.php';
        destroy_session();
        return false;
    }

    // Update last_seen_at
    $pdo->prepare("UPDATE user_sessions SET last_seen_at = NOW() WHERE id = :sid")
        ->execute([':sid' => $sid]);

    return true;
}

/**
 * Revoke a single session (must belong to the user).
 */
function revoke_session(PDO $pdo, int $userId, string $sessionId): bool
{
    $stmt = $pdo->prepare("
        UPDATE user_sessions
        SET revoked_at = NOW()
        WHERE id = :sid AND user_id = :uid AND revoked_at IS NULL
    ");
    $stmt->execute([':sid' => $sessionId, ':uid' => $userId]);
    return $stmt->rowCount() > 0;
}

/**
 * Revoke every session for a user except the current one.
 * Returns the number of sessions killed.
 */
function revoke_other_sessions(PDO $pdo, int $userId): int
{
    $current = session_id();
    $stmt = $pdo->prepare("
        UPDATE user_sessions
        SET revoked_at = NOW()
        WHERE user_id = :uid
          AND id <> :current
          AND revoked_at IS NULL
    ");
    $stmt->execute([':uid' => $userId, ':current' => $current]);
    return $stmt->rowCount();
}

/**
 * Clean up old sessions (called on login or via cron).
 * Deletes rows older than 30 days or long-revoked.
 */
function cleanup_old_sessions(PDO $pdo): int
{
    $stmt = $pdo->prepare("
        DELETE FROM user_sessions
        WHERE last_seen_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
           OR (revoked_at IS NOT NULL AND revoked_at < DATE_SUB(NOW(), INTERVAL 7 DAY))
    ");
    $stmt->execute();
    return $stmt->rowCount();
}

/**
 * Best-effort device label from a user agent string.
 */
function parse_device_label(string $ua): string
{
    if ($ua === '') return 'Unknown device';
    $uaLower = strtolower($ua);

    // Operating system
    $os = 'Unknown';
    if (str_contains($uaLower, 'windows'))   $os = 'Windows';
    elseif (str_contains($uaLower, 'iphone') || str_contains($uaLower, 'ipad')) $os = 'iOS';
    elseif (str_contains($uaLower, 'android')) $os = 'Android';
    elseif (str_contains($uaLower, 'mac os') || str_contains($uaLower, 'macintosh')) $os = 'macOS';
    elseif (str_contains($uaLower, 'linux'))  $os = 'Linux';

    // Browser
    $browser = 'Browser';
    if (str_contains($uaLower, 'edg/'))       $browser = 'Edge';
    elseif (str_contains($uaLower, 'chrome')) $browser = 'Chrome';
    elseif (str_contains($uaLower, 'firefox')) $browser = 'Firefox';
    elseif (str_contains($uaLower, 'safari')) $browser = 'Safari';

    // Form factor
    $device = 'Desktop';
    if (str_contains($uaLower, 'mobile')) $device = 'Mobile';
    elseif (str_contains($uaLower, 'tablet') || str_contains($uaLower, 'ipad')) $device = 'Tablet';

    return "{$browser} on {$os} ({$device})";
}