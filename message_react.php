<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require 'includes/messages.php';

header('Content-Type: application/json; charset=utf-8');

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

if (!isset($_SESSION['user_id'])) {
    $respond(401, ['success' => false, 'error' => 'Not logged in.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(405, ['success' => false, 'error' => 'Method not allowed.']);
}

$submittedToken = (string)($_POST['csrf_token'] ?? '');
$expectedToken = (string)($_SESSION['csrf_token'] ?? '');
if ($expectedToken === '' || !hash_equals($expectedToken, $submittedToken)) {
    $respond(403, ['success' => false, 'error' => 'Invalid or missing CSRF token.']);
}
csrf_verify();

if (!rate_limit_check($pdo, client_ip(), 'message_react', 60, 60)) {
    header('Retry-After: 60');
    $respond(429, ['success' => false, 'error' => 'Too many reactions. Slow down.']);
}

$me = (int)$_SESSION['user_id'];
$messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
$emoji = isset($_POST['emoji']) ? trim((string)$_POST['emoji']) : '';
$allowed = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

if ($messageId <= 0 || !in_array($emoji, $allowed, true)) {
    $respond(400, ['success' => false, 'error' => 'Invalid input.']);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        "SELECT m.id, m.deleted_at, c.user1_id, c.user2_id
         FROM messages m
         INNER JOIN conversations c ON c.id = m.conversation_id
         WHERE m.id = :id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':id' => $messageId]);
    $row = $stmt->fetch();

    $fail = static function (int $status, string $error) use ($pdo, $respond): void {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $respond($status, ['success' => false, 'error' => $error]);
    };

    if (!$row) {
        $fail(404, 'Message not found.');
    }
    if ((int)$row['user1_id'] !== $me && (int)$row['user2_id'] !== $me) {
        $fail(403, 'Not allowed.');
    }
    if (!empty($row['deleted_at'])) {
        $fail(400, 'Cannot react to a deleted message.');
    }

    $stmt = $pdo->prepare(
        "SELECT id, emoji FROM message_reactions
         WHERE message_id = :message AND user_id = :user
         LIMIT 1"
    );
    $stmt->execute([':message' => $messageId, ':user' => $me]);
    $existing = $stmt->fetch();

    if ($existing && $existing['emoji'] === $emoji) {
        $pdo->prepare("DELETE FROM message_reactions WHERE id = :id")
            ->execute([':id' => $existing['id']]);
        $action = 'removed';
    } elseif ($existing) {
        $pdo->prepare("UPDATE message_reactions SET emoji = :emoji WHERE id = :id")
            ->execute([':emoji' => $emoji, ':id' => $existing['id']]);
        $action = 'updated';
    } else {
        $pdo->prepare(
            "INSERT INTO message_reactions (message_id, user_id, emoji)
             VALUES (:message, :user, :emoji)"
        )->execute([':message' => $messageId, ':user' => $me, ':emoji' => $emoji]);
        $action = 'added';
    }

    $stmt = $pdo->prepare(
        "SELECT emoji, COUNT(*) AS reaction_count,
                SUM(CASE WHEN user_id = :viewer THEN 1 ELSE 0 END) AS mine
         FROM message_reactions
         WHERE message_id = :message
         GROUP BY emoji
         ORDER BY MIN(id) ASC"
    );
    $stmt->execute([':viewer' => $me, ':message' => $messageId]);
    $reactions = array_map(static function (array $reaction): array {
        return [
            'emoji' => (string)$reaction['emoji'],
            'count' => (int)$reaction['reaction_count'],
            'mine' => (int)$reaction['mine'] > 0,
        ];
    }, $stmt->fetchAll());

    $pdo->commit();

    $respond(200, [
        'success' => true,
        'action' => $action,
        'message_id' => $messageId,
        'reactions' => $reactions,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}
