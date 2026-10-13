<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require 'includes/conversations.php';
require 'includes/blocks.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: new_group.php');
    exit;
}

csrf_verify();
rate_limit_enforce($pdo, client_ip(), 'group_create', 10, 3600);

$me = (int)$_SESSION['user_id'];
$groupName = isset($_POST['group_name']) && is_string($_POST['group_name'])
    ? trim($_POST['group_name'])
    : '';
$memberInput = $_POST['member_ids'] ?? [];

$redirectWithFlash = static function (string $message): void {
    $_SESSION['flash'] = $message;
    header('Location: new_group.php');
    exit;
};

if ($groupName === '' || mb_strlen($groupName) > 100) {
    $redirectWithFlash('Enter a group name between 1 and 100 characters.');
}

if (!is_array($memberInput)) {
    $redirectWithFlash('Select at least one person to add to the group.');
}

$memberIds = [];
foreach ($memberInput as $memberId) {
    if (!is_string($memberId) && !is_int($memberId)) {
        continue;
    }

    $parsedId = filter_var($memberId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($parsedId !== false && $parsedId !== $me) {
        $memberIds[] = $parsedId;
    }
}
$memberIds = array_values(array_unique($memberIds));

if (!$memberIds) {
    $redirectWithFlash('Select at least one person other than yourself.');
}

if (count($memberIds) > 49) {
    $redirectWithFlash('A group can have at most 50 members, including you.');
}

$placeholders = [];
$params = [];
foreach ($memberIds as $index => $memberId) {
    $placeholder = ':member_' . $index;
    $placeholders[] = $placeholder;
    $params[$placeholder] = $memberId;
}

$stmt = $pdo->prepare(
    'SELECT id FROM users WHERE id IN (' . implode(', ', $placeholders) . ')'
);
$stmt->execute($params);
$validIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

$eligibleIds = [];
foreach ($validIds as $memberId) {
    if (!is_blocked_either($pdo, $me, $memberId)) {
        $eligibleIds[] = $memberId;
    }
}

if (!$eligibleIds) {
    $redirectWithFlash('No eligible members were selected. Choose someone you can message.');
}

try {
    $conversationId = create_group_conversation($pdo, $me, $groupName, $eligibleIds);
} catch (InvalidArgumentException $e) {
    $redirectWithFlash($e->getMessage());
}

header('Location: conversation.php?id=' . $conversationId);
exit;
