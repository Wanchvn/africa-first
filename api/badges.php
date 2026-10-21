<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/messages.php';

$user = api_require_admin($pdo);
$me = (int)$user['id'];

// Unread messages
$unread_messages = unread_message_count($pdo, $me);

// Unread notifications
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :me AND is_read = 0");
$stmt->execute([':me' => $me]);
$unread_notifications = (int)$stmt->fetchColumn();

api_ok([
    'messages' => $unread_messages,
    'notifications' => $unread_notifications
]);