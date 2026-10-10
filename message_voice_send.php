<?php
/**
 * Qarota — upload + send a voice message.
 * POST multipart/form-data:
 *   conversation_id
 *   csrf_token
 *   voice          (file)
 *   duration       (int, seconds)
 */
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require 'includes/messages.php';
require 'includes/blocks.php';

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
    rate_limit_enforce($pdo, client_ip(), 'message_voice', 10, 60);
} catch (Throwable $e) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => __('voice_too_many')]);
    exit;
}

$me         = (int)$_SESSION['user_id'];
$convId     = isset($_POST['conversation_id']) ? (int)$_POST['conversation_id'] : 0;
$duration   = isset($_POST['duration']) ? max(1, min(300, (int)$_POST['duration'])) : 0;

if ($convId <= 0 || !user_in_conversation($pdo, $convId, $me)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => __('messages_not_found')]);
    exit;
}

// Partner block check
$partnerId = conversation_partner($pdo, $convId, $me);
if ($partnerId && is_blocked_either($pdo, $me, $partnerId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => __('block_error_cannot_message')]);
    exit;
}

// ---- Validate the upload ----
if (empty($_FILES['voice']['name']) || $_FILES['voice']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => __('voice_missing')]);
    exit;
}

// Extension → allowed mime prefixes. We accept the browser's reported
// Content-Type AND fall back to finfo, because Windows often mis-detects webm.
$allowed_ext_by_mime_prefix = [
    'audio/webm'  => 'webm',
    'audio/ogg'   => 'ogg',
    'audio/mp4'   => 'm4a',
    'audio/mpeg'  => 'mp3',
    'audio/wav'   => 'wav',
    'audio/x-wav' => 'wav',
    'video/webm'  => 'webm',   // Windows finfo quirk: sometimes classifies as video
];
$max_size = 10 * 1024 * 1024;   // 10 MB

// 1. Take the mime the browser claimed
$browser_mime = $_FILES['voice']['type'] ?? '';

// 2. Also probe with finfo as a second opinion
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$probed_mime = finfo_file($finfo, $_FILES['voice']['tmp_name']);
finfo_close($finfo);

// 3. Normalise — strip any "codecs=..." suffix
function normalise_mime($m) {
    if (!$m) return '';
    $m = strtolower(trim($m));
    // "audio/webm;codecs=opus" → "audio/webm"
    if (strpos($m, ';') !== false) {
        $m = trim(substr($m, 0, strpos($m, ';')));
    }
    return $m;
}
$browser_mime = normalise_mime($browser_mime);
$probed_mime  = normalise_mime($probed_mime);

// 4. Accept if EITHER the browser mime or the probed mime is in our list
$final_mime = null;
if (isset($allowed_ext_by_mime_prefix[$browser_mime])) {
    $final_mime = $browser_mime;
} elseif (isset($allowed_ext_by_mime_prefix[$probed_mime])) {
    $final_mime = $probed_mime;
}

if ($final_mime === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => __('voice_bad_format')
    ]);
    exit;
}

if ($_FILES['voice']['size'] > $max_size) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => __('voice_too_big')]);
    exit;
}

// ---- Save file ----
$ext = $allowed_ext_by_mime_prefix[$final_mime];
$filename = bin2hex(random_bytes(16)) . '.' . $ext;
$uploadDir = __DIR__ . '/uploads/voice/';

if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}

$destination = $uploadDir . $filename;
$relPath     = 'uploads/voice/' . $filename;

if (!move_uploaded_file($_FILES['voice']['tmp_name'], $destination)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Save failed.']);
    exit;
}

// ---- Insert message ----
try {
    $pdo->beginTransaction();

    $ins = $pdo->prepare(
        "INSERT INTO messages (conversation_id, sender_id, body, message_type, voice_path, voice_duration)
         VALUES (:c, :s, '', 'voice', :vp, :vd)"
    );
    $ins->execute([
        ':c'  => $convId,
        ':s'  => $me,
        ':vp' => $relPath,
        ':vd' => $duration,
    ]);
    $messageId = (int)$pdo->lastInsertId();

    $pdo->prepare("UPDATE conversations SET last_message_at = NOW() WHERE id = :c")
        ->execute([':c' => $convId]);

    // Notify (same helper as text — respects prefs)
    notify_new_message($pdo, $partnerId, $me, $convId);

    $pdo->commit();
} catch (Throwable $e) {
    @unlink($destination);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Insert failed.']);
    exit;
}

// Return the new message row so JS can render it immediately
$stmt = $pdo->prepare(
    "SELECT id, sender_id, body, message_type, voice_path, voice_duration, created_at, deleted_at
     FROM messages WHERE id = :id LIMIT 1"
);
$stmt->execute([':id' => $messageId]);
$msg = $stmt->fetch();

echo json_encode(['success' => true, 'message' => $msg]);