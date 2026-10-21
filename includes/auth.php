<?php
// Add this to includes/auth.php

function api_require_admin($pdo) {
    $user = api_require_admin($pdo);
    
    // Check if is_admin column is true (1)
    if (!isset($user['is_admin']) || (int)$user['is_admin'] !== 1) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden. Admin access required.']);
        exit;
    }
    
    return $user;
}