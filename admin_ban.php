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
rate_limit_enforce($pdo, client_ip(), 'admin_ban', 20, 3600);

$admin_id = (int)$_SESSION['user_id'];

// ---- Admin gate ----
$stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $admin_id]);
if ((int)$stmt->fetchColumn() !== 1) {
    http_response_code(403);
    exit('Forbidden');
}

// ---- Input ----
$target_id  = (int)($_POST['target_id'] ?? 0);
$action     = $_POST['action'] ?? 'ban';               // 'ban' | 'unban'
$category   = trim($_POST['policy_category'] ?? '');
$note       = trim($_POST['admin_note'] ?? '');

// ---- Sanitize redirect ----
$redirect = $_POST['redirect'] ?? 'feed.php';
$allowed_redirects = ['feed.php', 'profile.php', 'admin_reports.php'];
$redirect_base = strtok($redirect, '?');
if (!in_array($redirect_base, $allowed_redirects, true)) {
    $redirect = 'feed.php';
}

// ---- Validation ----
$errors = [];

if ($target_id <= 0) {
    $errors[] = 'Invalid user.';
}
if ($target_id === $admin_id) {
    $errors[] = 'You cannot ban yourself.';
}
if (!in_array($action, ['ban', 'unban'], true)) {
    $errors[] = 'Invalid action.';
}
if ($action === 'ban' && !in_array($category, ['spam','harassment','hate_speech','misinformation','other'], true)) {
    $errors[] = 'Please choose a policy category.';
}
if ($note === '' || mb_strlen($note) < 5) {
    $errors[] = 'Please write a short note explaining the decision.';
} elseif (mb_strlen($note) > 500) {
    $errors[] = 'Note must be under 500 characters.';
}

// ---- Load target ----
$target = null;
if (empty($errors)) {
    $stmt = $pdo->prepare("SELECT id, username, is_admin FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $target_id]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        $errors[] = 'User not found.';
    } elseif ((int)$target['is_admin'] === 1) {
        $errors[] = 'You cannot ban another admin.';
    }
}

// ---- Execute ----
if (empty($errors)) {
    $pdo->beginTransaction();
    try {
        if ($action === 'ban') {
            // 1. Mark user as banned
            $stmt = $pdo->prepare("
                UPDATE users
                SET is_banned = 1,
                    ban_reason = :reason,
                    banned_at = NOW(),
                    banned_by = :admin
                WHERE id = :uid
            ");
            $stmt->execute([
                ':reason' => $category . ' — ' . $note,
                ':admin'  => $admin_id,
                ':uid'    => $target_id,
            ]);

            // 2. Write moderation log
            $stmt = $pdo->prepare("
                INSERT INTO moderation_log
                    (action_type, policy_category, admin_note, admin_id, created_at)
                VALUES
                    ('user_banned', :cat, :note, :admin, NOW())
            ");
            $stmt->execute([
                ':cat'   => $category,
                ':note'  => 'Banned @' . $target['username'] . ': ' . $note,
                ':admin' => $admin_id,
            ]);

            $_SESSION['flash_success'] = 'User banned.';
        } else {
            // 1. Lift the ban
            $stmt = $pdo->prepare("
                UPDATE users
                SET is_banned = 0,
                    ban_reason = NULL,
                    banned_at = NULL,
                    banned_by = NULL
                WHERE id = :uid
            ");
            $stmt->execute([':uid' => $target_id]);

            // 2. Write moderation log
            $stmt = $pdo->prepare("
                INSERT INTO moderation_log
                    (action_type, admin_note, admin_id, created_at)
                VALUES
                    ('user_unbanned', :note, :admin, NOW())
            ");
            $stmt->execute([
                ':note'  => 'Unbanned @' . $target['username'] . ': ' . $note,
                ':admin' => $admin_id,
            ]);

            $_SESSION['flash_success'] = 'User unbanned.';
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('admin_ban error: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'Could not complete the action: ' . $e->getMessage();
    }
} else {
    $_SESSION['flash_error'] = implode(' ', $errors);
}

header('Location: ' . $redirect);
exit;