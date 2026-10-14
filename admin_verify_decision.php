<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require_once __DIR__ . '/includes/verification.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_verifications.php');
    exit;
}

csrf_verify();
rate_limit_enforce($pdo, client_ip(), 'admin_verify_decision', 30, 3600);

$admin_id = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $admin_id]);
if ((int)$stmt->fetchColumn() !== 1) {
    http_response_code(403);
    exit;
}

$app_id = (int)($_POST['application_id'] ?? 0);
$action = $_POST['action'] ?? '';
$note   = trim($_POST['admin_note'] ?? '');

$errors = [];

if ($app_id <= 0)                                    $errors[] = 'Invalid application.';
if (!in_array($action, ['approve','reject'], true))  $errors[] = 'Invalid action.';
if (mb_strlen($note) < 5 || mb_strlen($note) > 500)  $errors[] = 'Note must be between 5 and 500 characters.';

$app = null;
if (empty($errors)) {
    $stmt = $pdo->prepare("
        SELECT a.*, u.username
        FROM verification_applications a
        INNER JOIN users u ON u.id = a.user_id
        WHERE a.id = :id LIMIT 1
    ");
    $stmt->execute([':id' => $app_id]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$app)                    $errors[] = 'Application not found.';
    elseif ($app['status'] !== 'pending') $errors[] = 'This application has already been resolved.';
}

if (empty($errors)) {
    $new_status = $action === 'approve' ? 'approved' : 'rejected';
    $pdo->beginTransaction();
    try {
        // 1. Update application
        $pdo->prepare("
            UPDATE verification_applications
            SET status = :s,
                admin_id = :admin,
                admin_note = :note,
                resolved_at = NOW(),
                docs_delete_after = DATE_ADD(NOW(), INTERVAL 30 DAY)
            WHERE id = :id
        ")->execute([
            ':s'     => $new_status,
            ':admin' => $admin_id,
            ':note'  => $note,
            ':id'    => $app_id,
        ]);

        // 2. Grant the badge on approve
        if ($action === 'approve') {
            $vtype = verification_type_for_category((string)$app['category']);
            $pdo->prepare("
                UPDATE users
                SET verification_type = :t,
                    verified_at = NOW(),
                    verified_by = :admin
                WHERE id = :uid
            ")->execute([':t' => $vtype, ':admin' => $admin_id, ':uid' => (int)$app['user_id']]);
        }

        // 3. Log publicly
        $logAction = $action === 'approve' ? 'verification_approved' : 'verification_rejected';
        $logNote   = ($action === 'approve' ? 'Approved' : 'Rejected')
                   . ' verification for @' . $app['username'] . ': ' . $note;

        $pdo->prepare("
            INSERT INTO moderation_log (action_type, admin_id, admin_note, created_at)
            VALUES (:a, :admin, :note, NOW())
        ")->execute([
            ':a'     => $logAction,
            ':admin' => $admin_id,
            ':note'  => mb_substr($logNote, 0, 500),
        ]);

        $pdo->commit();
        $_SESSION['flash_success'] = $action === 'approve'
            ? 'Application approved — badge granted.'
            : 'Application rejected.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('admin_verify_decision error: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'Could not save the decision.';
    }
} else {
    $_SESSION['flash_error'] = implode(' ', $errors);
}

header('Location: admin_verifications.php');
exit;