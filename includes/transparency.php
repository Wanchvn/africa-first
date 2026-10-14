<?php
/**
 * Qarota — transparency report helpers.
 */

/**
 * Compute the snapshot values for a given month.
 * $month must be a 'YYYY-MM-01' string.
 */
function compute_transparency_snapshot(PDO $pdo, string $month): array
{
    $start = $month . ' 00:00:00';
    $end   = date('Y-m-01 00:00:00', strtotime($month . ' +1 month'));

    $q = function (string $sql) use ($pdo, $start, $end): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':start' => $start, ':end' => $end]);
        return (int)$stmt->fetchColumn();
    };

    // moderation_log actions
    $posts_removed = $q("
        SELECT COUNT(*) FROM moderation_log
        WHERE action_type = 'post_removed'
          AND created_at >= :start AND created_at < :end
    ");

    $users_banned = $q("
        SELECT COUNT(*) FROM moderation_log
        WHERE action_type = 'user_banned'
          AND created_at >= :start AND created_at < :end
    ");

    $users_unbanned = $q("
        SELECT COUNT(*) FROM moderation_log
        WHERE action_type = 'user_unbanned'
          AND created_at >= :start AND created_at < :end
    ");

    $appeals_approved = $q("
        SELECT COUNT(*) FROM moderation_log
        WHERE action_type = 'appeal_approved'
          AND created_at >= :start AND created_at < :end
    ");

    $appeals_rejected = $q("
        SELECT COUNT(*) FROM moderation_log
        WHERE action_type = 'appeal_rejected'
          AND created_at >= :start AND created_at < :end
    ");

    $verifications_approved = $q("
        SELECT COUNT(*) FROM moderation_log
        WHERE action_type = 'verification_approved'
          AND created_at >= :start AND created_at < :end
    ");

    $verifications_rejected = $q("
        SELECT COUNT(*) FROM moderation_log
        WHERE action_type = 'verification_rejected'
          AND created_at >= :start AND created_at < :end
    ");

    $reports_received = $q("
        SELECT COUNT(*) FROM reports
        WHERE created_at >= :start AND created_at < :end
    ");

    $reports_dismissed = $q("
        SELECT COUNT(*) FROM moderation_log
        WHERE action_type = 'report_dismissed'
          AND created_at >= :start AND created_at < :end
    ");

    $new_users = $q("
        SELECT COUNT(*) FROM users
        WHERE created_at >= :start AND created_at < :end
    ");

    $new_posts = $q("
        SELECT COUNT(*) FROM posts
        WHERE created_at >= :start AND created_at < :end
    ");

    // Appeals submitted — count rows in appeals table
    $appeals_received = $q("
        SELECT COUNT(*) FROM appeals
        WHERE created_at >= :start AND created_at < :end
    ");

    // Data exports — if you have an export_log table, use it. Otherwise 0.
    $data_exports = 0;
    try {
        $data_exports = $q("
            SELECT COUNT(*) FROM export_log
            WHERE created_at >= :start AND created_at < :end
        ");
    } catch (Throwable $e) {
        // table doesn't exist — leave at 0
    }

    // Government requests — manually maintained, defaults to 0
    $gov_requests = 0;
    try {
        $gov_requests = $q("
            SELECT COUNT(*) FROM government_requests
            WHERE created_at >= :start AND created_at < :end
        ");
    } catch (Throwable $e) {
        // table doesn't exist — leave at 0
    }

    return [
        'month'                  => $month,
        'posts_removed'          => $posts_removed,
        'users_banned'           => $users_banned,
        'users_unbanned'         => $users_unbanned,
        'appeals_received'       => $appeals_received,
        'appeals_approved'       => $appeals_approved,
        'appeals_rejected'       => $appeals_rejected,
        'verifications_approved' => $verifications_approved,
        'verifications_rejected' => $verifications_rejected,
        'reports_received'       => $reports_received,
        'reports_dismissed'      => $reports_dismissed,
        'new_users'              => $new_users,
        'new_posts'              => $new_posts,
        'data_export_requests'   => $data_exports,
        'government_requests'    => $gov_requests,
    ];
}

/**
 * Upsert a snapshot row for the given month.
 */
function save_transparency_snapshot(PDO $pdo, array $snap): void
{
    $stmt = $pdo->prepare("
        INSERT INTO transparency_snapshots
            (month, posts_removed, users_banned, users_unbanned,
             appeals_received, appeals_approved, appeals_rejected,
             verifications_approved, verifications_rejected,
             reports_received, reports_dismissed, new_users, new_posts,
             data_export_requests, government_requests)
        VALUES
            (:month, :pr, :ub, :uu,
             :ar, :aa, :aj,
             :va, :vj,
             :rr, :rd, :nu, :np,
             :de, :gr)
        ON DUPLICATE KEY UPDATE
            posts_removed          = VALUES(posts_removed),
            users_banned           = VALUES(users_banned),
            users_unbanned         = VALUES(users_unbanned),
            appeals_received       = VALUES(appeals_received),
            appeals_approved       = VALUES(appeals_approved),
            appeals_rejected       = VALUES(appeals_rejected),
            verifications_approved = VALUES(verifications_approved),
            verifications_rejected = VALUES(verifications_rejected),
            reports_received       = VALUES(reports_received),
            reports_dismissed      = VALUES(reports_dismissed),
            new_users              = VALUES(new_users),
            new_posts              = VALUES(new_posts),
            data_export_requests   = VALUES(data_export_requests),
            government_requests    = VALUES(government_requests)
    ");
    $stmt->execute([
        ':month' => $snap['month'],
        ':pr'    => $snap['posts_removed'],
        ':ub'    => $snap['users_banned'],
        ':uu'    => $snap['users_unbanned'],
        ':ar'    => $snap['appeals_received'],
        ':aa'    => $snap['appeals_approved'],
        ':aj'    => $snap['appeals_rejected'],
        ':va'    => $snap['verifications_approved'],
        ':vj'    => $snap['verifications_rejected'],
        ':rr'    => $snap['reports_received'],
        ':rd'    => $snap['reports_dismissed'],
        ':nu'    => $snap['new_users'],
        ':np'    => $snap['new_posts'],
        ':de'    => $snap['data_export_requests'],
        ':gr'    => $snap['government_requests'],
    ]);
}

/**
 * Ensure a snapshot exists for the current month. Called on page load
 * so the report always has fresh data without needing cron.
 */
function ensure_current_month_snapshot(PDO $pdo): void
{
    $month = date('Y-m-01');

    // Only recompute if missing or older than 1 hour
    $stmt = $pdo->prepare("
        SELECT updated_at FROM transparency_snapshots
        WHERE month = :m LIMIT 1
    ");
    $stmt->execute([':m' => $month]);
    $updated = $stmt->fetchColumn();

    if ($updated && strtotime($updated) > time() - 3600) {
        return;
    }

    $snap = compute_transparency_snapshot($pdo, $month);
    save_transparency_snapshot($pdo, $snap);
}