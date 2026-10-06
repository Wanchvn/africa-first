<?php

/**
 * Generate (or reuse) the CSRF token for the current session.
 */
function csrf_token() {
    if (session_status() === PHP_SESSION_NONE) {
        require_once __DIR__ . '/session.php';
        start_secure_session();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Output a hidden input field with the CSRF token.
 * Use inside every state-changing <form>.
 */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Verify the CSRF token from a POST request.
 * Call at the top of any state-changing endpoint.
 * Dies with 403 if invalid.
 */
function csrf_verify() {
    if (session_status() === PHP_SESSION_NONE) {
        require_once __DIR__ . '/session.php';
        start_secure_session();
    }

    $submitted = $_POST['csrf_token'] ?? '';
    $expected  = $_SESSION['csrf_token'] ?? '';

    if ($expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        die('Invalid or missing CSRF token. Please reload the page and try again.');
    }
}