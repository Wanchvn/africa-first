<?php
/**
 * Qarota — API token helpers
 *
 * Tokens are random 32-byte strings. We store a SHA-256 hash,
 * never the plaintext token, so a DB leak can't be used to impersonate users.
 */

const API_TOKEN_TTL_DAYS = 90;

/**
 * Create a new token for a user. Returns [token_plaintext, token_row_id].
 * The plaintext is only shown once — after this call, it's gone.
 */
function api_issue_token(PDO $pdo, int $userId, string $deviceName = ''): array
{
    $token = bin2hex(random_bytes(32));          // 64 hex chars
    $hash  = hash('sha256', $token);

    $stmt = $pdo->prepare("
        INSERT INTO api_tokens (user_id, token_hash, device_name, expires_at)
        VALUES (:u, :h, :d, DATE_ADD(NOW(), INTERVAL :days DAY))
    ");
    $stmt->execute([
        ':u'    => $userId,
        ':h'    => $hash,
        ':d'    => $deviceName,
        ':days' => API_TOKEN_TTL_DAYS,
    ]);

    return [
        'token'    => $token,
        'token_id' => (int)$pdo->lastInsertId(),
    ];
}

/**
 * Look up the user for a given Authorization header.
 * Returns the user row (id, username, display_name, avatar, is_admin) or null.
 */
function api_auth_user(PDO $pdo, ?string $authHeader): ?array
{
    if (!$authHeader || stripos($authHeader, 'Bearer ') !== 0) {
        return null;
    }
    $token = trim(substr($authHeader, 7));
    if ($token === '') return null;

    $hash = hash('sha256', $token);

    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.display_name, u.avatar, u.is_admin,
               u.is_banned, u.verification_type, t.id AS token_id
        FROM api_tokens t
        INNER JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = :h
          AND (t.expires_at IS NULL OR t.expires_at > NOW())
        LIMIT 1
    ");
    $stmt->execute([':h' => $hash]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) return null;
    if ((int)$user['is_banned'] === 1) {
        // Banned users can't use the API.
        return null;
    }

    // Touch last_used_at
    $pdo->prepare("UPDATE api_tokens SET last_used_at = NOW() WHERE id = :id")
        ->execute([':id' => (int)$user['token_id']]);

    return $user;
}

/**
 * Revoke a single token (for logout).
 */
function api_revoke_token(PDO $pdo, int $tokenId): void
{
    $pdo->prepare("DELETE FROM api_tokens WHERE id = :id")
        ->execute([':id' => $tokenId]);
}

/**
 * Revoke all tokens for a user (for "log out everywhere").
 */
function api_revoke_all(PDO $pdo, int $userId): void
{
    $pdo->prepare("DELETE FROM api_tokens WHERE user_id = :u")
        ->execute([':u' => $userId]);
}

/**
 * Send a JSON error and exit.
 */
function api_error(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

/**
 * Send a JSON success and exit.
 */
function api_ok(array $data = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

/**
 * Require the request to be authenticated. Dies with 401 if not.
 * Returns the authenticated user row on success.
 */
function api_require_auth(PDO $pdo): array
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    $user = api_auth_user($pdo, $auth);
    if (!$user) {
        api_error(401, 'Not authenticated.');
    }
    return $user;
}

/**
 * Require an authenticated administrator.
 */
function api_require_admin(PDO $pdo): array
{
    $user = api_require_auth($pdo);
    if (!isset($user['is_admin']) || (int)$user['is_admin'] !== 1) {
        api_error(403, 'Forbidden. Admin access required.');
    }
    return $user;
}