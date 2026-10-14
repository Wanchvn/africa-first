<?php
/**
 * Qarota — TOTP (RFC 6238) implementation.
 *
 * Compatible with Google Authenticator, Authy, 1Password,
 * Microsoft Authenticator, and any standard TOTP app.
 *
 * Secrets are stored as base32 (RFC 4648) strings — the format
 * every authenticator app expects when you type or scan them.
 */

/**
 * Generate a new random TOTP secret (base32 encoded).
 */
function totp_generate_secret(int $bytes = 20): string
{
    return totp_base32_encode(random_bytes($bytes));
}

/**
 * Base32 encode (RFC 4648, no padding).
 */
function totp_base32_encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binary   = '';
    foreach (str_split($data) as $char) {
        $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $output = '';
    foreach (str_split($binary, 5) as $chunk) {
        $output .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $output;
}

/**
 * Base32 decode.
 */
function totp_base32_decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper($b32);
    $b32 = preg_replace('/[^A-Z2-7]/', '', $b32);
    if ($b32 === '') return '';

    $binary = '';
    foreach (str_split($b32) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) continue;
        $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }

    $output = '';
    foreach (str_split($binary, 8) as $byte) {
        if (strlen($byte) < 8) continue;
        $output .= chr(bindec($byte));
    }
    return $output;
}

/**
 * Generate a 6-digit TOTP code for the given secret and time.
 *
 * @param string $secret      Base32 secret
 * @param int|null $timestamp Unix timestamp (default: now)
 * @param int $period         Time step in seconds (default 30)
 * @param int $digits         Code length (default 6)
 */
function totp_generate_code(string $secret, ?int $timestamp = null, int $period = 30, int $digits = 6): string
{
    $timestamp = $timestamp ?? time();
    $key       = totp_base32_decode($secret);
    $counter   = intdiv($timestamp, $period);

    // Counter as 64-bit big-endian binary
    $binCounter = pack('N*', 0) . pack('N*', $counter);

    $hash = hash_hmac('sha1', $binCounter, $key, true);

    // Dynamic truncation
    $offset = ord($hash[19]) & 0x0F;
    $part   = substr($hash, $offset, 4);
    $value  = unpack('N', $part)[1] & 0x7FFFFFFF;

    $code = (string)($value % (10 ** $digits));
    return str_pad($code, $digits, '0', STR_PAD_LEFT);
}

/**
 * Verify a user-supplied TOTP code with a tolerance window.
 *
 * Accepts codes from the current step, ±$window steps.
 * Default window of 1 gives roughly 90 seconds of validity.
 */
function totp_verify(string $secret, string $code, int $window = 1, int $period = 30, int $digits = 6): bool
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== $digits) return false;

    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        $candidate = totp_generate_code($secret, $now + ($i * $period), $period, $digits);
        if (hash_equals($candidate, $code)) {
            return true;
        }
    }
    return false;
}

/**
 * Build an otpauth:// URI for QR code generation.
 */
function totp_otpauth_uri(string $secret, string $account, string $issuer = 'Qarota'): string
{
    return 'otpauth://totp/'
        . rawurlencode($issuer . ':' . $account)
        . '?secret=' . urlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

/**
 * Generate a batch of one-time backup codes.
 *
 * @return string[]  Array of 10 human-friendly codes like "3f2a-8b1c"
 */
function totp_generate_backup_codes(int $count = 10): array
{
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $raw = bin2hex(random_bytes(4));   // 8 hex chars
        $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
    }
    return $codes;
}

/**
 * Normalize a backup code for hashing: strip dashes, lowercase.
 */
function totp_normalize_backup_code(string $code): string
{
    return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $code));
}