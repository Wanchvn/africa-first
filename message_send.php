<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require 'includes/messages.php';

// Detect AJAX early — used at the bottom to decide JSON vs redirect.
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
       && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Only send JSON headers when we actually intend to return JSON.
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
}

if (!isset($_SESSION['user_id'])) {
    if ($isAjax) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    } else {
        header('Location: login.php');
    }
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    } else {
        header('Location: messages.php');
    }
    exit;
}

csrf_verify();

try {
    rate_limit_enforce($pdo, client_ip(), 'message', 30, 60);
} catch (Throwable $e) {
    if ($isAjax) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Too many messages. Slow down.']);
    } else {
        $_SESSION['flash_error'] = 'Too many messages. Slow down.';
        header('Location: messages.php');
    }
    exit;
}

$me   = (int) $_SESSION['user_id'];
$conv = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;
$body = isset($_POST['body']) ? (string) $_POST['body'] : '';

if ($conv <= 0 || !user_in_conversation($pdo, $conv, $me)) {
    if ($isAjax) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => __('messages_not_found')]);
    } else {
        $_SESSION['flash_error'] = __('messages_not_found');
        header('Location: messages.php');
    }
    exit;
}

try {
    $id = send_message($pdo, $conv, $me, $body);

    $s = $pdo->prepare(
        "SELECT id, sender_id, body, created_at FROM messages WHERE id = :id LIMIT 1"
    );
    $s->execute([':id' => $id]);
    $msg = $s->fetch();

    require_once __DIR__ . '/includes/crypto.php';
    if ($msg) {
        $msg['body'] = crypto_decrypt($msg['body']);
    }

    if ($isAjax) {
        echo json_encode(['success' => true, 'message' => $msg]);
    } else {
        // Native form submit fallback — redirect back to the thread.
        header('Location: conversation.php?id=' . $conv);
    }
} catch (InvalidArgumentException $e) {
    if ($isAjax) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } else {
        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: conversation.php?id=' . $conv);
    }
} catch (Throwable $e) {
    error_log('message_send error: ' . $e->getMessage());
    if ($isAjax) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server error.']);
    } else {
        $_SESSION['flash_error'] = 'Could not send message.';
        header('Location: conversation.php?id=' . $conv);
    }
}