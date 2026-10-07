<?php

/**
 * Suggest a username based on an email address.
 * Returns a unique username that doesn't exist in the database yet.
 *
 * @param PDO    $pdo   Database connection
 * @param string $email The user's email
 * @return string       A unique, DB-safe username
 */
function suggest_username($pdo, $email) {
    // Take the part before the @
    $base = strstr($email, '@', true);
    if ($base === false || $base === '') {
        $base = 'user';
    }

    // Clean it: lowercase, replace non-alphanumeric with dots, collapse multiple dots
    $base = strtolower($base);
    $base = preg_replace('/[^a-z0-9]+/', '.', $base);
    $base = trim($base, '.');
    $base = preg_replace('/\.+/', '.', $base);

    // Ensure minimum length
    if (strlen($base) < 3) {
        $base = 'user.' . $base;
    }

    // Truncate to leave room for suffixes
    $base = substr($base, 0, 26);

    // Try the base first
    if (is_username_available($pdo, $base)) {
        return $base;
    }

    // Append a number and try up to 99 times
    for ($i = 2; $i <= 99; $i++) {
        $candidate = $base . $i;
        if (is_username_available($pdo, $candidate)) {
            return $candidate;
        }
    }

    // Fallback: append random hex (extremely unlikely to be reached)
    return $base . bin2hex(random_bytes(3));
}

/**
 * Check whether a username is available (not taken).
 */
function is_username_available($pdo, $username) {
    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE username = :username");
    $stmt->execute([':username' => $username]);
    return !$stmt->fetch();
}

/**
 * Validate a username format.
 * Returns null if valid, or an error message if invalid.
 */
function validate_username($username) {
    if (strlen($username) < 3) {
        return 'Username must be at least 3 characters.';
    }
    if (strlen($username) > 30) {
        return 'Username must be 30 characters or less.';
    }
    if (!preg_match('/^[a-z0-9._]+$/', $username)) {
        return 'Username can only contain lowercase letters, numbers, dots, and underscores.';
    }
    if (preg_match('/^[._]/', $username) || preg_match('/[._]$/', $username)) {
        return 'Username cannot start or end with a dot or underscore.';
    }
    return null;
}