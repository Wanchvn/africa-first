<?php
/**
 * Qarota — Block helpers
 */

if (!function_exists('is_blocked')) {
    function is_blocked(PDO $pdo, int $blockerId, int $blockedId): bool
    {
        if ($blockerId <= 0 || $blockedId <= 0) return false;
        $stmt = $pdo->prepare(
            "SELECT 1 FROM user_blocks WHERE blocker_id = :b AND blocked_id = :t LIMIT 1"
        );
        $stmt->execute([':b' => $blockerId, ':t' => $blockedId]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('is_blocked_either')) {
    function is_blocked_either(PDO $pdo, int $a, int $b): bool
    {
        if ($a <= 0 || $b <= 0) return false;
        $stmt = $pdo->prepare(
            "SELECT 1 FROM user_blocks
             WHERE (blocker_id = :a AND blocked_id = :b)
                OR (blocker_id = :b AND blocked_id = :a)
             LIMIT 1"
        );
        $stmt->execute([':a' => $a, ':b' => $b]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('block_user')) {
    function block_user(PDO $pdo, int $blockerId, int $blockedId): void
    {
        if ($blockerId <= 0 || $blockedId <= 0) {
            throw new InvalidArgumentException('Invalid user IDs.');
        }
        if ($blockerId === $blockedId) {
            throw new InvalidArgumentException('Cannot block yourself.');
        }
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO user_blocks (blocker_id, blocked_id) VALUES (:b, :t)"
        );
        $stmt->execute([':b' => $blockerId, ':t' => $blockedId]);

        $pdo->prepare(
            "DELETE FROM follows
             WHERE (follower_id = :a AND following_id = :b)
                OR (follower_id = :b AND following_id = :a)"
        )->execute([':a' => $blockerId, ':b' => $blockedId]);
    }
}

if (!function_exists('unblock_user')) {
    function unblock_user(PDO $pdo, int $blockerId, int $blockedId): void
    {
        $stmt = $pdo->prepare(
            "DELETE FROM user_blocks WHERE blocker_id = :b AND blocked_id = :t"
        );
        $stmt->execute([':b' => $blockerId, ':t' => $blockedId]);
    }
}

if (!function_exists('list_blocked')) {
    function list_blocked(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            "SELECT u.id, u.username, u.display_name, u.avatar, ub.created_at
             FROM user_blocks ub
             INNER JOIN users u ON u.id = ub.blocked_id
             WHERE ub.blocker_id = :me
             ORDER BY ub.created_at DESC"
        );
        $stmt->execute([':me' => $userId]);
        return $stmt->fetchAll();
    }
}

if (!function_exists('blocked_count')) {
    function blocked_count(PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_blocks WHERE blocker_id = :me");
        $stmt->execute([':me' => $userId]);
        return (int)$stmt->fetchColumn();
    }
}