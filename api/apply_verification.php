<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$user = api_require_admin($pdo); 
$user_id = (int)$user['id'];

// Ensure the user doesn't already have a pending application
$stmt = $pdo->prepare("SELECT id FROM verification_applications WHERE user_id = :uid AND status = 'pending'");
$stmt->execute([':uid' => $user_id]);
if ($stmt->fetchColumn()) {
    echo json_encode(['success' => false, 'error' => 'You already have a pending verification request.']);
    exit;
}

$full_name = trim($_POST['full_legal_name'] ?? '');
$category  = trim($_POST['category'] ?? 'personal');
$country   = trim($_POST['country'] ?? '');
$id_type   = trim($_POST['id_type'] ?? '');
$id_number = trim($_POST['id_number'] ?? '');
$reason    = trim($_POST['reason'] ?? '');

if (empty($full_name) || empty($id_number) || empty($reason)) {
    echo json_encode(['success' => false, 'error' => 'Please fill in all required fields.']);
    exit;
}

// Handle File Uploads
$upload_dir = __DIR__ . '/../storage/verifications/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$front_path = null;
$back_path = null;

function handle_upload($file_key, $upload_dir) {
    if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $ext = pathinfo($_FILES[$file_key]['name'], PATHINFO_EXTENSION);
    $filename = uniqid($file_key . '_') . '.' . $ext;
    $destination = $upload_dir . $filename;
    
    if (move_uploaded_file($_FILES[$file_key]['tmp_name'], $destination)) {
        return 'storage/verifications/' . $filename; // Relative path for database
    }
    return null;
}

$front_path = handle_upload('id_front', $upload_dir);
$back_path = handle_upload('id_back', $upload_dir);

if (!$front_path) {
    echo json_encode(['success' => false, 'error' => 'Front ID image is required.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO verification_applications 
        (user_id, status, category, full_legal_name, country, id_type, id_number, reason, id_front_path, id_back_path, created_at)
        VALUES 
        (:uid, 'pending', :category, :name, :country, :id_type, :id_number, :reason, :front, :back, NOW())
    ");
    
    $stmt->execute([
        ':uid' => $user_id,
        ':category' => $category,
        ':name' => $full_name,
        ':country' => $country,
        ':id_type' => $id_type,
        ':id_number' => $id_number,
        ':reason' => $reason,
        ':front' => $front_path,
        ':back' => $back_path
    ]);

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    error_log('Verification application error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}