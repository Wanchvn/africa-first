<?php

/**
 * Check if an action is allowed for the current identifier.
 * Returns true if allowed, false if rate limit exceeded.
 *
 * @param PDO    $pdo         Database connection
 * @param string $identifier  Usually IP address, optionally with extra key
 * @param string $action      Action name (login, register, like, etc.)
 * @param int    $limit       Max actions allowed in window
 * @param int    $window_sec  Window size in seconds
 * @return bool
 */
function rate_limit_check($pdo, $identifier, $action, $limit, $window_sec) {
    // Prune old entries for this identifier+action (older than window)
    $stmt = $pdo->prepare("
        DELETE FROM rate_limits
        WHERE identifier = :id AND action = :action
          AND created_at < (NOW() - INTERVAL :seconds SECOND)
    ");
    $stmt->execute([
        ':id' => $identifier,
        ':action' => $action,
        ':seconds' => $window_sec,
    ]);

    // Count remaining entries within window
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM rate_limits
        WHERE identifier = :id AND action = :action
    ");
    $stmt->execute([
        ':id' => $identifier,
        ':action' => $action,
    ]);
    $count = (int)$stmt->fetchColumn();

    if ($count >= $limit) {
        return false;
    }

    // Record this attempt
    $stmt = $pdo->prepare("
        INSERT INTO rate_limits (identifier, action)
        VALUES (:id, :action)
    ");
    $stmt->execute([
        ':id' => $identifier,
        ':action' => $action,
    ]);

    return true;
}

/**
 * Enforce a rate limit or die with 429.
 * Convenience wrapper around rate_limit_check().
 */
function rate_limit_enforce($pdo, $identifier, $action, $limit, $window_sec) {
    if (!rate_limit_check($pdo, $identifier, $action, $limit, $window_sec)) {
        http_response_code(429);
        header('Retry-After: ' . $window_sec);
        die('Too many requests. Please slow down and try again later.');
    }
}

/**
 * Get the client's IP address.
 * Handles proxies (HostAfrica's Caddy may forward via X-Forwarded-For).
 */
function client_ip() {
    // If behind a trusted proxy, use the forwarded IP
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}