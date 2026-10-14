<?php
/**
 * Qarota — backfill transparency snapshots for the last N months.
 * Run once after deploying the transparency feature.
 *
 * Usage:
 *   php cron/backfill_transparency.php [months=6]
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/transparency.php';

$months = isset($argv[1]) ? max(1, (int)$argv[1]) : 6;

for ($i = $months - 1; $i >= 0; $i--) {
    $month = date('Y-m-01', strtotime("-{$i} months"));
    try {
        $snap = compute_transparency_snapshot($pdo, $month);
        save_transparency_snapshot($pdo, $snap);
        echo "Backfilled snapshot for {$month}\n";
    } catch (Throwable $e) {
        echo "ERROR for {$month}: " . $e->getMessage() . "\n";
    }
}

echo "Done.\n";