<?php
/**
 * Qarota — Direct messaging helpers
 */

require_once __DIR__ . '/blocks.php';

/**
 * Get or create a 1-to-1 conversation between two users.
 * Stores the lower user ID as user1_id so pairs are unique.
 */
function get_or_create_conversation(PDO $pdo, int $a, int $b): int
{
    if ($a <= 0 || $b <= 0) {
        throw new InvalidArgumentException('Invalid user IDs.');
    }
    if ($a === $b) {
        throw new InvalidArgumentException('Cannot message yourself.');
    }

    // Block check — no new conversations across a block
    if (is_blocked_either($pdo, $a, $b)) {
        throw new InvalidArgumentException('Cannot start a conversation with this user.');
    }

    $u1 = min($a, $b);
    $u2 = max($a, $b);

    $stmt = $pdo->prepare(
        "SELECT id FROM conversations WHERE user1_id = :u1 AND user2_id = :u2 LIMIT 1"
    );
    $stmt->execute([':u1' => $u1, ':u2' => $u2]);
    $id = $stmt->fetchColumn();
    if ($id) return (int) $id;

    $ins = $pdo->prepare(
        "INSERT INTO conversations (user1_id, user2_id, last_message_at)
         VALUES (:u1, :u2, NULL)"
    );
    $ins->execute([':u1' => $u1, ':u2' => $u2]);
    return (int) $pdo->lastInsertId();
}

/** Verify the given user is a participant in the conversation. */
function user_in_conversation(PDO $pdo, int $convId, int $userId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM conversations
         WHERE id = :c AND (user1_id = :u OR user2_id = :u)
         LIMIT 1"
    );
    $stmt->execute([':c' => $convId, ':u' => $userId]);
    return (bool) $stmt->fetchColumn();
}

/** Return the other participant's user ID. */
function conversation_partner(PDO $pdo, int $convId, int $userId): ?int
{
    $stmt = $pdo->prepare(
        "SELECT user1_id, user2_id FROM conversations WHERE id = :c LIMIT 1"
    );
    $stmt->execute([':c' => $convId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if ((int)$row['user1_id'] === $userId) return (int)$row['user2_id'];
    if ((int)$row['user2_id'] === $userId) return (int)$row['user1_id'];
    return null;
}

/**
 * Insert a notification row for a new message, respecting user prefs.
 * Must be called inside the same transaction as the message insert.
 */
function notify_new_message(PDO $pdo, int $recipientId, int $senderId, int $convId): void
{
    $pref = $pdo->prepare(
        "SELECT notify_paused, notify_messages FROM users WHERE id = :id LIMIT 1"
    );
    $pref->execute([':id' => $recipientId]);
    $u = $pref->fetch();
    if (!$u) return;
    if ((int)($u['notify_paused'] ?? 0) === 1) return;
    if ((int)($u['notify_messages'] ?? 1) !== 1) return;

    $ins = $pdo->prepare(
        "INSERT INTO notifications (user_id, actor_id, type, conversation_id)
         VALUES (:user_id, :actor, 'message', :conv)"
    );
    $ins->execute([
        ':user_id' => $recipientId,
        ':actor'   => $senderId,
        ':conv'    => $convId,
    ]);
}

/**
 * Send a message. Returns the new message ID.
 * Also fires a notification for the recipient.
 * Refuses to send when either party has blocked the other.
 */
function send_message(PDO $pdo, int $convId, int $senderId, string $body): int
{
    $body = trim($body);
    if ($body === '') {
        throw new InvalidArgumentException('Empty message.');
    }
    if (mb_strlen($body) > 5000) {
        throw new InvalidArgumentException('Message too long.');
    }

    // Look up the conversation + recipient once, up front
    $pair = $pdo->prepare(
        "SELECT user1_id, user2_id FROM conversations WHERE id = :c LIMIT 1"
    );
    $pair->execute([':c' => $convId]);
    $row = $pair->fetch();
    if (!$row) {
        throw new InvalidArgumentException('Conversation not found.');
    }
    $recipientId = ((int)$row['user1_id'] === $senderId)
        ? (int)$row['user2_id']
        : (int)$row['user1_id'];

    // Block check — either direction stops the send
    if (is_blocked_either($pdo, $senderId, $recipientId)) {
        throw new InvalidArgumentException('Cannot send a message to this user.');
    }

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            "INSERT INTO messages (conversation_id, sender_id, body)
             VALUES (:c, :s, :b)"
        );
        $ins->execute([':c' => $convId, ':s' => $senderId, ':b' => $body]);
        $id = (int) $pdo->lastInsertId();

        $upd = $pdo->prepare(
            "UPDATE conversations SET last_message_at = NOW() WHERE id = :c"
        );
        $upd->execute([':c' => $convId]);

        if ($recipientId > 0) {
            notify_new_message($pdo, $recipientId, $senderId, $convId);
        }

        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Mark all incoming messages in a conversation as read. */
function mark_conversation_read(PDO $pdo, int $convId, int $userId): void
{
    $stmt = $pdo->prepare(
        "UPDATE messages
         SET is_read = 1
         WHERE conversation_id = :c AND sender_id <> :u AND is_read = 0"
    );
    $stmt->execute([':c' => $convId, ':u' => $userId]);

    // Also clear 'message' notifications for this conversation
    $stmt = $pdo->prepare(
        "UPDATE notifications
         SET is_read = 1
         WHERE user_id = :u
           AND type = 'message'
           AND conversation_id = :c
           AND is_read = 0"
    );
    $stmt->execute([':u' => $userId, ':c' => $convId]);
}

/** Total unread messages across all conversations for a user. */
function unread_message_count(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM messages m
         JOIN conversations c ON c.id = m.conversation_id
         WHERE m.is_read = 0
           AND m.sender_id <> :u
           AND (c.user1_id = :u OR c.user2_id = :u)"
    );
    $stmt->execute([':u' => $userId]);
    return (int) $stmt->fetchColumn();
}



/* ============================================================
   Typing indicator helpers
   ============================================================ */

/**
 * Record that $userId is typing in $convId right now.
 * Idempotent — UPSERT on the composite primary key.
 */
function set_typing(PDO $pdo, int $convId, int $userId): void
{
    if ($convId <= 0 || $userId <= 0) return;

    $stmt = $pdo->prepare(
        "INSERT INTO typing_indicators (conversation_id, user_id, updated_at)
         VALUES (:c, :u, NOW())
         ON DUPLICATE KEY UPDATE updated_at = NOW()"
    );
    $stmt->execute([':c' => $convId, ':u' => $userId]);
}

/**
 * Has $userId pinged as "typing" in $convId within the last 4 seconds?
 * 4s is long enough to bridge the polling interval without lag, short
 * enough that a user who stops typing disappears quickly.
 */
function is_typing(PDO $pdo, int $convId, int $userId): bool
{
    if ($convId <= 0 || $userId <= 0) return false;

    $stmt = $pdo->prepare(
        "SELECT 1 FROM typing_indicators
         WHERE conversation_id = :c
           AND user_id = :u
           AND updated_at > (NOW() - INTERVAL 4 SECOND)
         LIMIT 1"
    );
    $stmt->execute([':c' => $convId, ':u' => $userId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Clear typing state — called when the user sends a message,
 * so the other side's indicator hides immediately.
 */
function clear_typing(PDO $pdo, int $convId, int $userId): void
{
    if ($convId <= 0 || $userId <= 0) return;

    $stmt = $pdo->prepare(
        "DELETE FROM typing_indicators
         WHERE conversation_id = :c AND user_id = :u"
    );
    $stmt->execute([':c' => $convId, ':u' => $userId]);
}



/**
 * Soft-delete a message. Only the sender can delete their own message.
 * Returns true on success, false if not allowed / not found.
 */
function delete_message(PDO $pdo, int $messageId, int $userId): bool
{
    if ($messageId <= 0 || $userId <= 0) return false;

    // Verify: message exists, is not already deleted, sender is this user
    $stmt = $pdo->prepare(
        "SELECT id FROM messages
         WHERE id = :id
           AND sender_id = :u
           AND deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->execute([':id' => $messageId, ':u' => $userId]);
    if (!$stmt->fetchColumn()) return false;

    $upd = $pdo->prepare(
        "UPDATE messages
         SET body = '',
             deleted_at = NOW(),
             deleted_by = :u
         WHERE id = :id"
    );
    $upd->execute([':u' => $userId, ':id' => $messageId]);
    return true;
}