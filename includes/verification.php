<?php
/**
 * Qarota — verification application helpers.
 */

/**
 * Handle an uploaded verification document.
 *
 * @param  array  $file    Entry from $_FILES[...]
 * @param  int    $userId  Owner
 * @param  string $slot    'front'|'back'|'selfie'
 * @return string          Relative path like storage/verifications/12/front_abc.jpg
 * @throws RuntimeException on any failure
 */
function handle_verification_upload(array $file, int $userId, string $slot): string
{
    $projectRoot = dirname(__DIR__);

    $maxBytes = 5 * 1024 * 1024;   // 5 MB per file
    $allowed  = ['image/jpeg', 'image/png', 'image/webp'];
    $extMap   = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $slots    = ['front', 'back', 'selfie'];

    if (!in_array($slot, $slots, true)) {
        throw new RuntimeException('Invalid document slot.');
    }

    if (!isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed.');
    }

    if (($file['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('Each document must be smaller than 5 MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);

    if (!in_array($mime, $allowed, true)) {
        throw new RuntimeException('Only JPG, PNG, or WEBP images are accepted.');
    }

    $ext      = $extMap[$mime];
    $subdir   = 'storage/verifications/' . $userId;
    $dir      = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subdir);

    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the verification storage directory.');
    }

    $name = $slot . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
    $full = $dir . DIRECTORY_SEPARATOR . $name;
    $rel  = $subdir . '/' . $name;

    if (!move_uploaded_file($file['tmp_name'], $full)) {
        throw new RuntimeException('Could not save the document.');
    }
    @chmod($full, 0640);

    return $rel;
}

/**
 * Return the user's most recent verification application, or null.
 */
function verification_application_status(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM verification_applications
        WHERE user_id = :uid
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Delete all files in a user's verification folder (best-effort).
 */
function delete_verification_docs(int $userId): void
{
    $projectRoot = dirname(__DIR__);
    $dir = $projectRoot . '/storage/verifications/' . $userId;

    if (!is_dir($dir)) return;

    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

/**
 * Map an application category to the resulting users.verification_type.
 */
function verification_type_for_category(string $category): string
{
    return match ($category) {
        'organization', 'business' => 'organization',
        default                    => 'personal',
    };
}

/**
 * Mask an ID number for display: keep first 2 and last 2 chars.
 */
function mask_id_number(string $number): string
{
    $len = mb_strlen($number);
    if ($len <= 4) return str_repeat('•', $len);
    return mb_substr($number, 0, 2)
         . str_repeat('•', max(1, $len - 4))
         . mb_substr($number, -2);
}




/**
 * Delete verification documents for applications whose retention date has passed.
 *
 * Safe to call on every admin page load — it only touches rows where
 * docs_delete_after < NOW() and files still exist.
 *
 * @return int Number of applications cleaned
 */
function cleanup_expired_verification_docs(PDO $pdo): int
{
    // Only rows that had their documents scheduled for deletion
    $stmt = $pdo->query("
        SELECT id, user_id, id_front_path, id_back_path, selfie_path
        FROM verification_applications
        WHERE docs_delete_after IS NOT NULL
          AND docs_delete_after < NOW()
          AND (id_front_path IS NOT NULL
               OR id_back_path IS NOT NULL
               OR selfie_path IS NOT NULL)
        LIMIT 50
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) return 0;

    $cleaned = 0;
    foreach ($rows as $row) {
        $userId = (int)$row['user_id'];

        // Delete the user's verification folder contents
        delete_verification_docs($userId);

        // Null out the paths + clear the retention marker
        $pdo->prepare("
            UPDATE verification_applications
            SET id_front_path     = NULL,
                id_back_path      = NULL,
                selfie_path       = NULL,
                docs_delete_after = NULL
            WHERE id = :id
        ")->execute([':id' => (int)$row['id']]);

        $cleaned++;
    }

    return $cleaned;
}