<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/sessions_tracker.php';

$n = cleanup_old_sessions($pdo);
echo "[" . date('c') . "] Cleaned {$n} old sessions.\n";