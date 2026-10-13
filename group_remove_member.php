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
rate_limit_enforce($pdo, client_ip(), 'group_remove_member', 30, 3600);

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

if ($targetId <= 0 || $targetId === $me) {
    $redirect(__('group_remove_self_error'));
}

if (!user_in_conversation_members($pdo, $convId, $targetId)) {
    $redirect('That user is not a member of this group.');
}

if (is_conversation_admin($pdo, $convId, $targetId) && is_last_admin($pdo, $convId, $targetId)) {
    $redirect(__('group_last_admin_error'));
}

$stmt = $pdo->prepare('SELECT id, username, display_name FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $targetId]);
$targetUser = $stmt->fetch();
if (!$targetUser) {
    $redirect('That user could not be found.');
}

remove_conversation_member($pdo, $convId, $targetId);
$actorName = (string)($pdo->query('SELECT display_name FROM users WHERE id = ' . (int)$me)->fetchColumn() ?: $pdo->query('SELECT username FROM users WHERE id = ' . (int)$me)->fetchColumn());
$targetName = (string)($targetUser['display_name'] ?: $targetUser['username']);
post_system_message($pdo, $convId, $me, sprintf(__('system_removed_member'), $actorName ?: 'You', $targetName));

$_SESSION['flash'] = __('group_removed');
header('Location: conversation.php?id=' . $convId);
exit;
