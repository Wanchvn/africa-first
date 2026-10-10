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

// Load the chosen language
$lang_file = __DIR__ . '/' . $_SESSION['lang'] . '.php';
if (!file_exists($lang_file)) {
    $lang_file = __DIR__ . '/en.php';
}
$TRANSLATIONS = require $lang_file;

// Always load English as a fallback source
$FALLBACK = require __DIR__ . '/en.php';

// Translation helper — falls back to English if a key is missing
if (!function_exists('__')) {
    function __($key) {
        global $TRANSLATIONS, $FALLBACK;

        // 1. Use the current language if the key exists there
        if (isset($TRANSLATIONS[$key]) && $TRANSLATIONS[$key] !== '') {
            return $TRANSLATIONS[$key];
        }

        // 2. Otherwise use English
        if (isset($FALLBACK[$key]) && $FALLBACK[$key] !== '') {
            return $FALLBACK[$key];
        }

        // 3. Last resort: return the raw key (helps you spot missing strings in dev)
        return $key;
    }
}