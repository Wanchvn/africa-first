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
rate_limit_enforce($pdo, client_ip(), 'group_rename', 20, 3600);

$me = (int)$_SESSION['user_id'];
$convId = (int)($_POST['conversation_id'] ?? 0);
$name = isset($_POST['name']) && is_string($_POST['name']) ? trim($_POST['name']) : '';

$redirect = static function (string $message) use ($convId): void {
    $_SESSION['flash'] = $message;
    header('Location: conversation.php?id=' . $convId);
    exit;
};

if ($convId <= 0 || !user_in_conversation($pdo, $convId, $me) || !is_conversation_admin($pdo, $convId, $me)) {
    $redirect(__('group_last_admin_error'));
}

if ($name === '' || mb_strlen($name) > 100) {
    $redirect('Enter a group name between 1 and 100 characters.');
}

$stmt = $pdo->prepare(
    'UPDATE conversations SET group_name = :name WHERE id = :id AND is_group = 1'
);
$stmt->execute([':name' => $name, ':id' => $convId]);

$_SESSION['flash'] = __('group_renamed');
header('Location: conversation.php?id=' . $convId);
exit;
