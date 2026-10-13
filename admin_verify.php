<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: feed.php');
    exit;
}

csrf_verify();
rate_limit_enforce($pdo, client_ip(), 'admin_verify', 20, 3600);

$admin_id = (int)$_SESSION['user_id'];

// Admin gate
$stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $admin_id]);
if ((int)$stmt->fetchColumn() !== 1) {
    http_response_code(403);
    exit('Forbidden');
}

$target_id = (int)($_POST['target_id'] ?? 0);
$action    = $_POST['action'] ?? '';
$type      = $_POST['verification_type'] ?? 'personal';
$note      = trim($_POST['admin_note'] ?? '');

// Sanitize redirect
$redirect = $_POST['redirect'] ?? 'feed.php';
$allowed  = ['feed.php', 'profile.php', 'admin_reports.php'];
$base     = strtok($redirect, '?');
if (!in_array($base, $allowed, true)) {
    $redirect = 'feed.php';
}

$errors = [];

if ($target_id <= 0)               $errors[] = 'Invalid user.';
if ($target_id === $admin_id)      $errors[] = 'You cannot verify yourself.';
if (!in_array($action, ['grant','revoke'], true)) $errors[] = 'Invalid action.';
if ($action === 'grant' && !in_array($type, ['personal','organization','staff'], true)) {
    $errors[] = 'Invalid verification type.';
}
if ($note === '' || mb_strlen($note) < 5) {
    $errors[] = 'Please write a short note explaining the decision.';
} elseif (mb_strlen($note) > 500) {
    $errors[] = 'Note must be under 500 characters.';
}

$target = null;
if (empty($errors)) {
    $stmt = $pdo->prepare("SELECT id, username, is_admin, verification_type FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $target_id]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) $errors[] = 'User not found.';
}

if (empty($errors)) {
    $pdo->beginTransaction();
    try {
        if ($action === 'grant') {
            $pdo->prepare("
                UPDATE users
                SET verification_type = :t,
                    verified_at = NOW(),
                    verified_by = :admin
                WHERE id = :uid
            ")->execute([':t' => $type, ':admin' => $admin_id, ':uid' => $target_id]);

            $log_action = 'user_verified';
            $log_note   = 'Verified @' . $target['username'] . ' (' . $type . '): ' . $note;
        } else {
            $pdo->prepare("
                UPDATE users
                SET verification_type = 'none',
                    verified_at = NULL,
                    verified_by = NULL
                WHERE id = :uid
            ")->execute([':uid' => $target_id]);

            $log_action = 'user_unverified';
            $log_note   = 'Removed verification from @' . $target['username'] . ': ' . $note;
        }

        $pdo->prepare("
            INSERT INTO moderation_log
                (action_type, admin_id, admin_note, created_at)
            VALUES
                (:action, :admin, :note, NOW())
        ")->execute([
            ':action' => $log_action,
            ':admin'  => $admin_id,
            ':note'   => mb_substr($log_note, 0, 500),
        ]);

        $pdo->commit();
        $_SESSION['flash_success'] = $action === 'grant' ? 'User verified.' : 'Verification removed.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('admin_verify error: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'Could not complete: ' . $e->getMessage();
    }
} else {
    $_SESSION['flash_error'] = implode(' ', $errors);
}

header('Location: ' . $redirect);
exit;