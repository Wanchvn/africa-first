<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'includes/csrf.php';
require 'includes/topics.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: topics.php');
    exit;
}

csrf_verify();

$user_id = (int)$_SESSION['user_id'];
$post_id = (int)($_POST['post_id'] ?? 0);
$topic_id = (int)($_POST['topic_id'] ?? 0);
$redirect = $_POST['redirect'] ?? 'topics.php';

if ($post_id <= 0 || $topic_id <= 0) {
    header('Location: ' . $redirect);
    exit;
}

// Verify the user is the curator of this topic
$stmt = $pdo->prepare("SELECT curator_id FROM topics WHERE id = :id");
$stmt->execute([':id' => $topic_id]);
$curator_id = $stmt->fetchColumn();

if ((int)$curator_id !== $user_id) {
    header('Location: ' . $redirect);
    exit;
}

// Remove the post from the topic
$stmt = $pdo->prepare("DELETE FROM post_topics WHERE post_id = :p AND topic_id = :t");
$stmt->execute([':p' => $post_id, ':t' => $topic_id]);

header('Location: ' . $redirect);
exit;