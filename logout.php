<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require_once __DIR__ . '/includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

csrf_verify();

// Clear any pending-2FA state before destroying the session
// (destroy_session wipes $_SESSION anyway, but explicit here is clearer)
unset(
    $_SESSION['pending_2fa_user_id'],
    $_SESSION['pending_2fa_started'],
    $_SESSION['pending_2fa_remember'],
    $_SESSION['pending_2fa_secret'],
    $_SESSION['fresh_backup_codes']
);

destroy_session();

header('Location: login.php');
exit;