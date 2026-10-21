<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';

$user = api_require_auth($pdo);
$me = (int)$user['id'];

$conversation_id = (int)($_POST['conversation_id'] ?? 0);
$type = $_POST['type'] ?? 'image';

if ($conversation_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid conversation ID.']);
    exit;
}

// Create the directory if it doesn't exist
$upload_dir = __DIR__ . '/../uploads/messages/';
if (!is_dir($upload_dir)) {
    if (!mkdir($upload_dir, 0777, true)) {
        echo json_encode(['success' => false, 'error' => 'Server could not create upload folder.']);
        exit;
    }
}

try {
    $media_path = null;

    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $ext = $type === 'voice' ? 'm4a' : pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $prefix = $type === 'voice' ? 'voice_' : 'img_';
        $filename = uniqid($prefix) . '.' . $ext;
        
        $destination = $upload_dir . $filename;
        
        if (move_uploaded_file($_FILES['file']['tmp_name'], $destination)) {
            $media_path = 'uploads/messages/' . $filename;
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save file to server.']);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'No file received or upload error: ' . ($_FILES['file']['error'] ?? 'unknown')]);
        exit;
    }

    // Insert into database
    $stmt = $pdo->prepare("
        INSERT INTO messages (conversation_id, sender_id, body, message_type, created_at, is_read) 
        VALUES (:cid, :me, :body, :type, NOW(), 0)
    ");
    $stmt->execute([
        ':cid' => $conversation_id, 
        ':me' => $me, 
        ':body' => $media_path,
        ':type' => $type
    ]);

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    error_log('Media upload error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}