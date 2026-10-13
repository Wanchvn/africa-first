<?php
$file = __DIR__ . '/includes/conversations.php';
echo "Path: $file\n";
echo "Exists: " . (file_exists($file) ? 'yes' : 'no') . "\n";
echo "Size: " . filesize($file) . " bytes\n";
echo "MD5:  " . md5_file($file) . "\n\n";

$content = file_get_contents($file);
$count = substr_count($content, 'function user_in_conversation_members');
echo "Occurrences of 'function user_in_conversation_members': $count\n";