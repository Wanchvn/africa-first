<?php

function security_log($action, $details = '') {
    $log_dir = __DIR__ . '/../storage';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }

    $log_file = $log_dir . '/security.log';

    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }

    $user_id = $_SESSION['user_id'] ?? 0;
    $username = $_SESSION['username'] ?? 'guest';
    $time = date('Y-m-d H:i:s');

    $line = sprintf(
        "[%s] [%s] [user:%s/%d] [%s] %s\n",
        $time,
        $ip,
        $username,
        $user_id,
        $action,
        $details
    );

    @file_put_contents($log_file, $line, FILE_APPEND);
}