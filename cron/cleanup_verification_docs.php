<?php
/**
 * Qarota — verification document retention cleanup.
 * Run daily via cron or Windows Task Scheduler.
 *
 * Example cron (Linux):
 *   0 3 * * *  /usr/bin/php /var/www/qarota/cron/cleanup_verification_docs.php
 *
 * Example Windows Task Scheduler:
 *   Program:   C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\qarota\cron\cleanup_verification_docs.php
 *   Trigger:   Daily at 03:00
 */

// This file lives in cron/, so the project root is one level up
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/verification.php';

// Let it run for a while if there's a big backlog
set_time_limit(300);
@ini_set('memory_limit', '256M');

$totalCleaned = 0;
$passes       = 0;

// Loop in batches of 50 until there's nothing left to clean
do {
    $cleaned = cleanup_expired_verification_docs($pdo);
    $totalCleaned += $cleaned;
    $passes++;
} while ($cleaned > 0 && $passes < 20);   // cap at 1000 apps per run

$timestamp = date('c');
if ($totalCleaned > 0) {
    echo "[{$timestamp}] Cleaned documents for {$totalCleaned} application(s) in {$passes} pass(es).\n";
    error_log("verification cleanup (cron): {$totalCleaned} application(s) cleaned");
} else {
    echo "[{$timestamp}] No expired verification documents to clean.\n";
}

exit(0);