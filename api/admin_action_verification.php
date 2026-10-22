<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error(405, 'Method not allowed.');
}

$auth_user = api_require_auth($pdo);

// Ensure they are an admin
if (empty($auth_user['is_admin']) || (int)$auth_user['is_admin'] !== 1) {
    api_error(403, 'Unauthorized. Admin access required.');
}

$data = json_decode(file_get_contents('php://input'), true);
$id = (int)($data['id'] ?? 0);
$action = $data['action'] ?? ''; 
$type = $data['type'] ?? 'dating'; 

if (!$id || !in_array($action, ['approve', 'reject'], true) || !in_array($type, ['dating', 'profile'], true)) {
    api_error(400, 'Missing ID or invalid action.');
}

try {
    $pdo->beginTransaction();

    if ($type === 'dating') {
        $stmt = $pdo->prepare("
            SELECT user_id, status
            FROM dating_verification
            WHERE id = ?
            FOR UPDATE
        ");
        $stmt->execute([$id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            $pdo->rollBack();
            api_error(404, 'Dating verification request not found.');
        }
        if ($request['status'] !== 'pending') {
            $pdo->rollBack();
            api_error(409, 'This verification request has already been handled.');
        }

        $targetUserId = (int)$request['user_id'];
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $stmt = $pdo->prepare("
            UPDATE dating_verification
            SET status = ?
            WHERE id = ? AND status = 'pending' AND user_id = ?
        ");
        $stmt->execute([$status, $id, $targetUserId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Verification request changed while being processed.');
        }

        if ($action === 'approve') {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$targetUserId]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('Verification request has no associated user.');
            }

            $stmt = $pdo->prepare("INSERT INTO dating_profiles (user_id, is_active) VALUES (?, 1) ON DUPLICATE KEY UPDATE is_active = 1");
            $stmt->execute([$targetUserId]);
        }

    } else {
        $stmt = $pdo->prepare("
            SELECT user_id, status
            FROM verification_requests
            WHERE id = ?
            FOR UPDATE
        ");
        $stmt->execute([$id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            $pdo->rollBack();
            api_error(404, 'Profile verification request not found.');
        }
        if ($request['status'] !== 'pending') {
            $pdo->rollBack();
            api_error(409, 'This verification request has already been handled.');
        }

        $targetUserId = (int)$request['user_id'];
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $stmt = $pdo->prepare("
            UPDATE verification_requests
            SET status = ?
            WHERE id = ? AND status = 'pending' AND user_id = ?
        ");
        $stmt->execute([$status, $id, $targetUserId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Verification request changed while being processed.');
        }

        if ($action === 'approve') {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$targetUserId]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('Verification request has no associated user.');
            }

            $stmt = $pdo->prepare("UPDATE users SET verification_type = 'personal' WHERE id = ?");
            $stmt->execute([$targetUserId]);
        }
    }

    $pdo->commit();
    api_ok(['success' => true]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Admin Verification Error: " . $e->getMessage());
    api_error(500, 'Database error.');
}