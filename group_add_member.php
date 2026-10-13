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
require 'includes/blocks.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: messages.php');
    exit;
}

csrf_verify();
rate_limit_enforce($pdo, client_ip(), 'group_add_member', 30, 3600);

$me = (int)$_SESSION['user_id'];
$convId = (int)($_POST['conversation_id'] ?? 0);
$targetId = (int)($_POST['user_id'] ?? 0);

$redirect = static function (string $message) use ($convId): void {
    $_SESSION['flash'] = $message;
    header('Location: conversation.php?id=' . $convId);
    exit;
};

if ($convId <= 0 || !user_in_conversation($pdo, $convId, $me) || !is_conversation_admin($pdo, $convId, $me)) {
    $redirect(__('group_last_admin_error'));
}

if ($targetId <= 0) {
    $redirect('Choose a user to add.');
}

if (conversation_member_count($pdo, $convId) >= 50) {
    $redirect(__('group_full_error'));
}

$stmt = $pdo->prepare('SELECT id, username, display_name FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $targetId]);
$targetUser = $stmt->fetch();
if (!$targetUser) {
    $redirect('That user could not be found.');
}

if (user_in_conversation_members($pdo, $convId, $targetId)) {
    $redirect(__('group_already_member'));
}

if (is_blocked_either($pdo, $me, $targetId)) {
    $redirect(__('group_blocked_error'));
}

add_conversation_member($pdo, $convId, $targetId, 'member');

$stmt = $pdo->prepare('SELECT display_name, username FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $me]);
$actor = $stmt->fetch();
$actorName = (string)($actor['display_name'] ?: $actor['username']);
$targetName = (string)($targetUser['display_name'] ?: $targetUser['username']);
post_system_message($pdo, $convId, $me, sprintf(__('system_added_member'), $actorName ?: 'You', $targetName));

$_SESSION['flash'] = __('group_added');
header('Location: conversation.php?id=' . $convId);
exit;
