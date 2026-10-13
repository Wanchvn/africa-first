<?php
/**
 * Qarota — conversation helpers.
 * Introduced for group DM support. Existing 1-on-1 helpers in
 * includes/messages.php remain in place; these new functions work
 * for both 1-on-1 and group conversations.
 */

if (!function_exists('user_in_conversation_members')) {
    function user_in_conversation_members(PDO $pdo, int $convId, int $userId): bool
    {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM conversation_members
             WHERE conversation_id = :c AND user_id = :u LIMIT 1"
        );
        $stmt->execute([':c' => $convId, ':u' => $userId]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('conversation_member_count')) {
    function conversation_member_count(PDO $pdo, int $convId): int
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM conversation_members WHERE conversation_id = :c"
        );
        $stmt->execute([':c' => $convId]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('is_group_conversation')) {
    function is_group_conversation(PDO $pdo, int $convId): bool
    {
        $stmt = $pdo->prepare(
            "SELECT is_group FROM conversations WHERE id = :c LIMIT 1"
        );
        $stmt->execute([':c' => $convId]);
        return (int)$stmt->fetchColumn() === 1;
    }
}

if (!function_exists('is_conversation_admin')) {
    function is_conversation_admin(PDO $pdo, int $convId, int $userId): bool
    {
        $stmt = $pdo->prepare(
            "SELECT role FROM conversation_members
             WHERE conversation_id = :c AND user_id = :u LIMIT 1"
        );
        $stmt->execute([':c' => $convId, ':u' => $userId]);
        return $stmt->fetchColumn() === 'admin';
    }
}

if (!function_exists('is_last_admin')) {
    function is_last_admin(PDO $pdo, int $convId, int $userId): bool
    {
        if (!is_conversation_admin($pdo, $convId, $userId)) {
            return false;
        }
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM conversation_members
             WHERE conversation_id = :c AND role = 'admin'"
        );
        $stmt->execute([':c' => $convId]);
        return (int)$stmt->fetchColumn() === 1;
    }
}

if (!function_exists('count_admins')) {
    function count_admins(PDO $pdo, int $convId): int
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM conversation_members
             WHERE conversation_id = :c AND role = 'admin'"
        );
        $stmt->execute([':c' => $convId]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('promote_to_admin')) {
    function promote_to_admin(PDO $pdo, int $convId, int $userId): void
    {
        $stmt = $pdo->prepare(
            "UPDATE conversation_members SET role = 'admin'
             WHERE conversation_id = :c AND user_id = :u"
        );
        $stmt->execute([':c' => $convId, ':u' => $userId]);
    }
}

if (!function_exists('demote_to_member')) {
    function demote_to_member(PDO $pdo, int $convId, int $userId): void
    {
        $stmt = $pdo->prepare(
            "UPDATE conversation_members SET role = 'member'
             WHERE conversation_id = :c AND user_id = :u"
        );
        $stmt->execute([':c' => $convId, ':u' => $userId]);
    }
}

if (!function_exists('post_system_message')) {
    function post_system_message(PDO $pdo, int $convId, int $actorId, string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException('System message cannot be empty.');
        }
        $stmt = $pdo->prepare(
            "INSERT INTO messages (conversation_id, sender_id, body, message_type)
             VALUES (:c, :s, :b, 'system')"
        );
        $stmt->execute([':c' => $convId, ':s' => $actorId, ':b' => $text]);
        $messageId = (int)$pdo->lastInsertId();

        $upd = $pdo->prepare(
            "UPDATE conversations SET last_message_at = NOW() WHERE id = :c"
        );
        $upd->execute([':c' => $convId]);

        return $messageId;
    }
}

if (!function_exists('add_conversation_member')) {
    function add_conversation_member(PDO $pdo, int $convId, int $userId, string $role = 'member'): void
    {
        if (!in_array($role, ['member', 'admin'], true)) {
            $role = 'member';
        }
        $stmt = $pdo->prepare(
            "INSERT INTO conversation_members (conversation_id, user_id, role)
             VALUES (:c, :u, :r)
             ON DUPLICATE KEY UPDATE role = role"
        );
        $stmt->execute([':c' => $convId, ':u' => $userId, ':r' => $role]);
    }
}

if (!function_exists('remove_conversation_member')) {
    function remove_conversation_member(PDO $pdo, int $convId, int $userId): void
    {
        $stmt = $pdo->prepare(
            "DELETE FROM conversation_members
             WHERE conversation_id = :c AND user_id = :u"
        );
        $stmt->execute([':c' => $convId, ':u' => $userId]);
    }
}

if (!function_exists('mark_conversation_read_member')) {
    function mark_conversation_read_member(PDO $pdo, int $convId, int $userId): void
    {
        $stmt = $pdo->prepare(
            "UPDATE conversation_members SET last_read_at = NOW()
             WHERE conversation_id = :c AND user_id = :u"
        );
        $stmt->execute([':c' => $convId, ':u' => $userId]);
    }
}

if (!function_exists('conversation_partners')) {
    function conversation_partners(PDO $pdo, int $convId, int $userId): array
    {
        $stmt = $pdo->prepare(
            "SELECT user_id FROM conversation_members
             WHERE conversation_id = :c AND user_id <> :u
             ORDER BY joined_at ASC"
        );
        $stmt->execute([':c' => $convId, ':u' => $userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

if (!function_exists('create_group_conversation')) {
    function create_group_conversation(PDO $pdo, int $creatorId, string $name, array $memberIds): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('Group name required (max 100 chars).');
        }

        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        $memberIds = array_filter($memberIds, function ($id) use ($creatorId) {
            return $id > 0 && $id !== $creatorId;
        });

        if (empty($memberIds)) {
            throw new InvalidArgumentException('Add at least one member.');
        }
        if (count($memberIds) > 49) {
            throw new InvalidArgumentException('Max 50 members per group (including you).');
        }

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                "INSERT INTO conversations (is_group, group_name, created_by, user1_id, user2_id, last_message_at)
                 VALUES (1, :name, :creator, NULL, NULL, NOW())"
            );
            $ins->execute([':name' => $name, ':creator' => $creatorId]);
            $convId = (int)$pdo->lastInsertId();

            $addCreator = $pdo->prepare(
                "INSERT INTO conversation_members (conversation_id, user_id, role)
                 VALUES (:c, :u, 'admin')"
            );
            $addCreator->execute([':c' => $convId, ':u' => $creatorId]);

            $add = $pdo->prepare(
                "INSERT INTO conversation_members (conversation_id, user_id, role)
                 VALUES (:c, :u, 'member')"
            );
            foreach ($memberIds as $mid) {
                $add->execute([':c' => $convId, ':u' => $mid]);
            }

            $pdo->commit();
            return $convId;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}