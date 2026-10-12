<?php
/**
 * One-time migration for plaintext IP identifiers already stored by Qarota.
 * After deploying, run from the project root: php migrate_ip_hashes.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/proxy.php';
require __DIR__ . '/config/db.php';

$identifiers = $pdo->query('SELECT DISTINCT identifier FROM rate_limits')
    ->fetchAll(PDO::FETCH_COLUMN);
$update = $pdo->prepare(
    'UPDATE rate_limits SET identifier = :hashed WHERE identifier = :plain'
);
$updated_identifiers = 0;

$pdo->beginTransaction();
try {
    foreach ($identifiers as $identifier) {
        $identifier = (string)$identifier;
        if (preg_match('/\A[a-f0-9]{64}\z/iD', $identifier)) {
            continue;
        }

        $update->execute([
            ':hashed' => privacy_hash_identifier($identifier),
            ':plain' => $identifier,
        ]);
        $updated_identifiers += $update->rowCount() > 0 ? 1 : 0;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$migrated_log_lines = 0;
$log_file = __DIR__ . '/storage/security.log';
if (is_file($log_file)) {
    $log_handle = fopen($log_file, 'c+');
    if ($log_handle === false) {
        throw new RuntimeException('Could not open the existing security log');
    }

    $log_locked = false;
    try {
        if (!flock($log_handle, LOCK_EX)) {
            throw new RuntimeException('Could not lock the existing security log');
        }
        $log_locked = true;

        $contents = stream_get_contents($log_handle);
        if ($contents === false) {
            throw new RuntimeException('Could not read the existing security log');
        }

        $updated_contents = preg_replace_callback(
            '/^(\[[^\]]+\]) \[([^\]]+)\]/m',
            function ($matches) use (&$migrated_log_lines) {
                if (filter_var($matches[2], FILTER_VALIDATE_IP) === false) {
                    return $matches[0];
                }

                $migrated_log_lines++;
                return $matches[1] . ' [ip_hash:'
                    . privacy_hash_identifier($matches[2]) . ']';
            },
            $contents
        );
        if ($updated_contents === null) {
            throw new RuntimeException('Could not process the existing security log');
        }
        if ($updated_contents !== $contents) {
            if (fseek($log_handle, 0) !== 0 || !ftruncate($log_handle, 0)) {
                throw new RuntimeException('Could not update the existing security log');
            }
            $written = fwrite($log_handle, $updated_contents);
            if ($written !== strlen($updated_contents) || !fflush($log_handle)) {
                throw new RuntimeException('Could not update the existing security log');
            }
        }
    } finally {
        if ($log_locked) {
            flock($log_handle, LOCK_UN);
        }
        fclose($log_handle);
    }
}

echo sprintf(
    "Hashed %d existing rate-limit identifiers and %d security-log IPs.\n",
    $updated_identifiers,
    $migrated_log_lines
);
