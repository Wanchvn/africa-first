<?php
/**
 * Qarota — upload + send a video message.
 * POST multipart/form-data:
 *   conversation_id, csrf_token, video (file), duration (int), thumb (file, optional)
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
    rate_limit_enforce($pdo, client_ip(), 'message_video', 5, 60);
} catch (Throwable $e) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => __('video_too_many')]);
    exit;
}

$me       = (int)$_SESSION['user_id'];
$convId   = isset($_POST['conversation_id']) ? (int)$_POST['conversation_id'] : 0;
$duration = isset($_POST['duration']) ? max(1, min(120, (int)$_POST['duration'])) : 0;

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

if (empty($_FILES['video']['name']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => __('video_missing')]);
    exit;
}

// ---- Validate video ----
$allowed_video = [
    'video/mp4'       => 'mp4',
    'video/webm'      => 'webm',
    'video/quicktime' => 'mov',
    'video/x-matroska'=> 'mkv',
];
$max_size = 25 * 1024 * 1024; // 25 MB

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$v_mime = strtolower(finfo_file($finfo, $_FILES['video']['tmp_name']));
finfo_close($finfo);

// Also check the browser-declared type as fallback (Windows finfo quirks)
$browser_v = $_FILES['video']['type'] ?? '';
if (strpos($browser_v, ';') !== false) {
    $browser_v = trim(substr($browser_v, 0, strpos($browser_v, ';')));
}
$browser_v = strtolower($browser_v);

$final_v_mime = null;
if (isset($allowed_video[$v_mime])) {
    $final_v_mime = $v_mime;
} elseif (isset($allowed_video[$browser_v])) {
    $final_v_mime = $browser_v;
}

if ($final_v_mime === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => __('video_bad_format'),
        'debug_browser_mime' => $browser_v,
        'debug_probed_mime'  => $v_mime,
    ]);
    exit;
}
if ($_FILES['video']['size'] > $max_size) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => __('video_too_big')]);
    exit;
}

// ---- Save video ----
$vext = $allowed_video[$final_v_mime];
$vfilename = bin2hex(random_bytes(16)) . '.' . $vext;
$uploadDir = __DIR__ . '/uploads/dm_videos/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}

$vdest = $uploadDir . $vfilename;
$vrel  = 'uploads/dm_videos/' . $vfilename;

if (!move_uploaded_file($_FILES['video']['tmp_name'], $vdest)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Save failed.']);
    exit;
}

// ---- Optional thumbnail ----
$thumb_rel = null;
if (!empty($_FILES['thumb']['name']) && $_FILES['thumb']['error'] === UPLOAD_ERR_OK) {
    $allowed_img = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $tfinfo = finfo_open(FILEINFO_MIME_TYPE);
    $t_mime = strtolower(finfo_file($tfinfo, $_FILES['thumb']['tmp_name']));
    finfo_close($tfinfo);

    if (isset($allowed_img[$t_mime]) && $_FILES['thumb']['size'] < 2 * 1024 * 1024) {
        $text = $allowed_img[$t_mime];
        $tfilename = bin2hex(random_bytes(16)) . '.' . $text;
        $tdest = $uploadDir . $tfilename;
        if (move_uploaded_file($_FILES['thumb']['tmp_name'], $tdest)) {
            $thumb_rel = 'uploads/dm_videos/' . $tfilename;
        }
    }
}

// ---- Insert ----
try {
    $pdo->beginTransaction();

    $ins = $pdo->prepare(
        "INSERT INTO messages
            (conversation_id, sender_id, body, message_type, video_path, video_duration, video_thumb)
         VALUES (:c, :s, '', 'video', :vp, :vd, :vt)"
    );
    $ins->execute([
        ':c'  => $convId,
        ':s'  => $me,
        ':vp' => $vrel,
        ':vd' => $duration,
        ':vt' => $thumb_rel,
    ]);
    $messageId = (int)$pdo->lastInsertId();

    $pdo->prepare("UPDATE conversations SET last_message_at = NOW() WHERE id = :c")
        ->execute([':c' => $convId]);

    notify_new_message($pdo, $partnerId, $me, $convId);

    $pdo->commit();
} catch (Throwable $e) {
    @unlink($vdest);
    if ($thumb_rel) @unlink(__DIR__ . '/' . $thumb_rel);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Insert failed.']);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT id, sender_id, body, message_type, voice_path, voice_duration,
            image_path, video_path, video_duration, video_thumb, created_at, deleted_at
     FROM messages WHERE id = :id LIMIT 1"
);
$stmt->execute([':id' => $messageId]);
$msg = $stmt->fetch();

echo json_encode(['success' => true, 'message' => $msg]);