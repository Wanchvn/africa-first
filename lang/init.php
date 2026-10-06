<?php
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

// Available languages
$LANGUAGES = ['en', 'tw', 'fr', 'dg'];

// Determine current language
if (!isset($_SESSION['lang']) || !in_array($_SESSION['lang'], $LANGUAGES, true)) {
    $_SESSION['lang'] = 'en';
}

// Handle language switch (via ?setlang=xx)
if (isset($_GET['setlang']) && in_array($_GET['setlang'], $LANGUAGES, true)) {
    $_SESSION['lang'] = $_GET['setlang'];
    // Redirect to same page without the query string (prevents re-trigger on refresh)
    $clean_url = strtok($_SERVER['REQUEST_URI'], '?');
    header('Location: ' . $clean_url);
    exit;
}

// Load the language file
$lang_file = __DIR__ . '/' . $_SESSION['lang'] . '.php';
if (!file_exists($lang_file)) {
    $lang_file = __DIR__ . '/en.php';
}
$TRANSLATIONS = require $lang_file;

// Translation helper
if (!function_exists('__')) {
    function __($key) {
        global $TRANSLATIONS;
        return $TRANSLATIONS[$key] ?? $key;
    }
}