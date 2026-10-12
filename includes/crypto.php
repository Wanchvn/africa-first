<?php
/**
 * Qarota — AES-256-GCM encryption helpers.
 *
 * Storage format: "enc:v1:" . base64( nonce(12) || ciphertext || tag(16) )
 * Legacy plaintext rows (no prefix) are returned as-is on decrypt.
 *
 * NOTE: This is encryption at rest, not end-to-end. The server holds the key.
 */

function crypto_key(): string {
    static $key = null;
    if ($key !== null) return $key;

    $config_path = __DIR__ . '/../config/encryption.php';
    if (!file_exists($config_path)) {
        throw new RuntimeException('config/encryption.php missing');
    }
    $config = require $config_path;
    $hex = $config['key'] ?? '';

    if (!is_string($hex) || strlen($hex) !== 64 || !ctype_xdigit($hex)) {
        throw new RuntimeException('Invalid encryption key: expected 64 hex chars');
    }

    $key = hex2bin($hex);
    return $key;
}

function crypto_encrypt(string $plaintext): string {
    if ($plaintext === '') return '';

    $key   = crypto_key();
    $nonce = random_bytes(12);
    $tag   = '';

    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        '',
        16
    );

    if ($ciphertext === false) {
        throw new RuntimeException('Encryption failed');
    }

    return 'enc:v1:' . base64_encode($nonce . $ciphertext . $tag);
}

function crypto_decrypt(string $stored): string {
    if ($stored === '') return '';
    if (strpos($stored, 'enc:v1:') !== 0) {
        return $stored;
    }

    $key  = crypto_key();
    $blob = base64_decode(substr($stored, 7), true);
    if ($blob === false || strlen($blob) < 12 + 16) {
        return '';
    }

    $nonce      = substr($blob, 0, 12);
    $tag        = substr($blob, -16);
    $ciphertext = substr($blob, 12, -16);

    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );

    return $plaintext === false ? '' : $plaintext;
}

function crypto_is_encrypted(string $stored): bool {
    return strpos($stored, 'enc:v1:') === 0;
}

/**
 * Encrypt an arbitrary binary string for file storage.
 * The server holds the key; this is encryption at rest, not end-to-end encryption.
 */
function crypto_encrypt_blob(string $plaintext): string {
    if ($plaintext === '') return '';

    $key   = crypto_key();
    $nonce = random_bytes(12);
    $tag   = '';
    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        '',
        16
    );

    if ($ciphertext === false) {
        throw new RuntimeException('Blob encryption failed');
    }

    return 'enc:v1:' . $nonce . $ciphertext . $tag;
}

/**
 * Decrypt an encrypted file blob, passing legacy plaintext files through.
 */
function crypto_decrypt_blob(string $blob): string {
    if ($blob === '') return '';
    if (strpos($blob, 'enc:v1:') !== 0) {
        return $blob;
    }

    $encrypted = substr($blob, 7);
    if (strlen($encrypted) < 12 + 16) {
        return '';
    }

    $key        = crypto_key();
    $nonce      = substr($encrypted, 0, 12);
    $tag        = substr($encrypted, -16);
    $ciphertext = substr($encrypted, 12, -16);

    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );

    return $plaintext === false ? '' : $plaintext;
}
