<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/transparency.php';

$month = date('Y-m-01');
$snap  = compute_transparency_snapshot($pdo, $month);
save_transparency_snapshot($pdo, $snap);

echo "Refreshed snapshot for {$month}\n";