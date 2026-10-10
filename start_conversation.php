<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/messages.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$me     = (int) $_SESSION['user_id'];
$target = isset($_GET['user']) ? (int) $_GET['user'] : 0;

if ($target <= 0 || $target === $me) {
    header('Location: messages.php');
    exit;
}

$u = $pdo->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
$u->execute([':id' => $target]);
if (!$u->fetchColumn()) {
    header('Location: messages.php');
    exit;
}

$convId = get_or_create_conversation($pdo, $me, $target);
header('Location: conversation.php?id=' . $convId);
exit;