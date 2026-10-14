<?php
/**
 * Qarota — report helpers.
 */

/**
 * Resolve a report and notify the reporter.
 *
 * @param string $status  'actioned' | 'dismissed'
 * @return bool  true on success, false if the report doesn't exist or is already resolved
 * @throws RuntimeException on DB failure
 */
function resolve_report(
    PDO $pdo,
    int $reportId,
    int $adminId,
    string $status,
    string $note
): bool {
    if (!in_array($status, ['actioned', 'dismissed'], true)) {
        throw new InvalidArgumentException('Invalid resolution status.');
    }

    $stmt = $pdo->prepare("
        SELECT id, reporter_id, status FROM reports
        WHERE id = :id LIMIT 1
    ");
    $stmt->execute([':id' => $reportId]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        return false;
    }
    if ($report['status'] !== 'pending') {
        return false;   // already resolved
    }

    $pdo->beginTransaction();
    try {
        // Update report
        $pdo->prepare("
            UPDATE reports
            SET status = :s,
                resolved_at = NOW(),
                resolved_by = :admin,
                resolution_note = :note
            WHERE id = :id
        ")->execute([
            ':s'     => $status,
            ':admin' => $adminId,
            ':note'  => mb_substr($note, 0, 500),
            ':id'    => $reportId,
        ]);

        // Notify reporter (respect pause preference)
        $stmt = $pdo->prepare("SELECT notify_paused FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => (int)$report['reporter_id']]);
        $notify_paused = (int)$stmt->fetchColumn() === 1;

        if (!$notify_paused) {
            $notify_type = $status === 'actioned' ? 'report_resolved' : 'report_dismissed';
            $pdo->prepare("
                INSERT INTO notifications (user_id, actor_id, type, created_at)
                VALUES (:uid, :actor, :type, NOW())
            ")->execute([
                ':uid'   => (int)$report['reporter_id'],
                ':actor' => $adminId,
                ':type'  => $notify_type,
            ]);
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}