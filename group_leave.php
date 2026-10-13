<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require 'includes/messages.php';
require 'includes/conversations.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: messages.php');
    exit;
}

csrf_verify();
rate_limit_enforce($pdo, client_ip(), 'group_leave', 20, 3600);

$me = (int)$_SESSION['user_id'];
$convId = (int)($_POST['conversation_id'] ?? 0);

$redirect = static function (string $message): void {
    $_SESSION['flash'] = $message;
    header('Location: messages.php');
    exit;
};

if ($convId <= 0 || !user_in_conversation($pdo, $convId, $me)) {
    $redirect('You are not a member of that group.');
}

if (is_conversation_admin($pdo, $convId, $me) && is_last_admin($pdo, $convId, $me)) {
    $redirect(__('group_last_admin_error'));
}

$stmt = $pdo->prepare('SELECT display_name, username FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $me]);
$user = $stmt->fetch();
$actorName = (string)($user['display_name'] ?: $user['username']);

remove_conversation_member($pdo, $convId, $me);
post_system_message($pdo, $convId, $me, sprintf(__('system_left_group'), $actorName ?: 'User'));

if (conversation_member_count($pdo, $convId) === 0) {
    $pdo->prepare('DELETE FROM conversations WHERE id = :id')->execute([':id' => $convId]);
    header('Location: messages.php');
    exit;
}

$_SESSION['flash'] = __('group_left');
header('Location: messages.php');
exit;
