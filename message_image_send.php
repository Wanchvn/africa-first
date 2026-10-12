<?php
require_once __DIR__ . '/includes/bootstrap.php';
/**
 * Qarota — upload + send an image message.
 * POST multipart/form-data:
 *   conversation_id, csrf_token, image (file)
 */
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require 'includes/messages.php';
require 'includes/blocks.php';
require_once __DIR__ . '/includes/crypto.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

csrf_verify();

try {
    rate_limit_enforce($pdo, client_ip(), 'message_image', 20, 60);
} catch (Throwable $e) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => __('image_too_many')]);
    exit;
}

$me     = (int)$_SESSION['user_id'];
$convId = isset($_POST['conversation_id']) ? (int)$_POST['conversation_id'] : 0;

if ($convId <= 0 || !user_in_conversation($pdo, $convId, $me)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => __('messages_not_found')]);
    exit;
}

$partnerId = conversation_partner($pdo, $convId, $me);
if ($partnerId && is_blocked_either($pdo, $me, $partnerId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => __('block_error_cannot_message')]);
    exit;
}

if (empty($_FILES['image']['name']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => __('image_missing')]);
    exit;
}

$allowed = [
    'image/jpeg' => 'jpg',
    'image/jpg'  => 'jpg',   // some Windows finfo builds report this
    'image/pjpeg'=> 'jpg',   // IE quirk
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];
$max_size = 5 * 1024 * 1024;

// 1. Get the browser-declared mime, normalise it
$browser_mime = $_FILES['image']['type'] ?? '';
if (strpos($browser_mime, ';') !== false) {
    $browser_mime = trim(substr($browser_mime, 0, strpos($browser_mime, ';')));
}
$browser_mime = strtolower($browser_mime);

// 2. Also probe with finfo
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$probed_mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
finfo_close($finfo);
$probed_mime = strtolower($probed_mime);

// 3. Prefer browser mime if recognised; else fall back to finfo
$final_mime = null;
if (isset($allowed[$browser_mime])) {
    $final_mime = $browser_mime;
} elseif (isset($allowed[$probed_mime])) {
    $final_mime = $probed_mime;
}

if ($final_mime === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => __('upload_only_images'),
        'debug_browser_mime' => $browser_mime,
        'debug_probed_mime'  => $probed_mime,
    ]);
    exit;
}
if ($_FILES['image']['size'] > $max_size) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => __('upload_too_big')]);
    exit;
}

$ext = $allowed[$final_mime];
$filename = bin2hex(random_bytes(16)) . '.' . $ext . '.enc';
$uploadDir = __DIR__ . '/uploads/dm_images/';

if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Save failed.']);
        exit;
    }
}

$destination = $uploadDir . $filename;
$relPath     = 'uploads/dm_images/' . $filename;

$uploadedBlob = file_get_contents($_FILES['image']['tmp_name']);
if ($uploadedBlob === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Save failed.']);
    exit;
}

try {
    $encryptedBlob = crypto_encrypt_blob($uploadedBlob);
} catch (RuntimeException $e) {
    error_log('Image upload encryption failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Encryption failed.']);
    exit;
}

$written = file_put_contents($destination, $encryptedBlob, LOCK_EX);
if ($written !== strlen($encryptedBlob)) {
    if (file_exists($destination)) {
        unlink($destination);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Save failed.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $ins = $pdo->prepare(
        "INSERT INTO messages (conversation_id, sender_id, body, message_type, image_path)
         VALUES (:c, :s, '', 'image', :ip)"
    );
    $ins->execute([':c' => $convId, ':s' => $me, ':ip' => $relPath]);
    $messageId = (int)$pdo->lastInsertId();

    $pdo->prepare("UPDATE conversations SET last_message_at = NOW() WHERE id = :c")
        ->execute([':c' => $convId]);

    notify_new_message($pdo, $partnerId, $me, $convId);

    $pdo->commit();
} catch (Throwable $e) {
    @unlink($destination);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Insert failed.']);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT id, sender_id, body, message_type, voice_path, voice_duration,
            image_path, created_at, deleted_at
     FROM messages WHERE id = :id LIMIT 1"
);
$stmt->execute([':id' => $messageId]);
$msg = $stmt->fetch();

echo json_encode(['success' => true, 'message' => $msg]);