<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require_once __DIR__ . '/includes/crypto.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$me = (int)$_SESSION['user_id'];
$q  = trim($_GET['q'] ?? '');

if (mb_strlen($q) < 2) {
    echo json_encode([
        'success' => true,
        'query'   => $q,
        'results' => [],
        'count'   => 0,
    ]);
    exit;
}

if (mb_strlen($q) > 100) {
    $q = mb_substr($q, 0, 100);
}

$scan_limit = 2000;

// 1. Find conversations the user is part of
$stmt = $pdo->prepare("
    SELECT conversation_id
    FROM conversation_members
    WHERE user_id = :me
");
$stmt->execute([':me' => $me]);
$conversation_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

if (empty($conversation_ids)) {
    echo json_encode(['success' => true, 'query' => $q, 'results' => [], 'count' => 0]);
    exit;
}

// 2. Pull recent text messages
$placeholders = implode(',', array_fill(0, count($conversation_ids), '?'));

$sql = "
    SELECT
        m.id,
        m.conversation_id,
        m.sender_id,
        m.body,
        m.message_type,
        m.created_at,
        m.deleted_at,
        sender.username AS sender_username,
        sender.display_name AS sender_display_name
    FROM messages m
    INNER JOIN users sender ON sender.id = m.sender_id
    WHERE m.conversation_id IN ($placeholders)
      AND m.deleted_at IS NULL
      AND m.message_type = 'text'
    ORDER BY m.created_at DESC
    LIMIT " . (int)$scan_limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($conversation_ids);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Load conversation titles
$ph2 = implode(',', array_fill(0, count($conversation_ids), '?'));
$stmt = $pdo->prepare("
    SELECT
        c.id AS conversation_id,
        c.is_group,
        c.group_name,
        (SELECT u.display_name FROM conversation_members cm
            INNER JOIN users u ON u.id = cm.user_id
            WHERE cm.conversation_id = c.id AND cm.user_id <> ?
            LIMIT 1) AS other_display_name,
        (SELECT u.username FROM conversation_members cm
            INNER JOIN users u ON u.id = cm.user_id
            WHERE cm.conversation_id = c.id AND cm.user_id <> ?
            LIMIT 1) AS other_username
    FROM conversations c
    WHERE c.id IN ($ph2)
");
$stmt->execute(array_merge([$me, $me], $conversation_ids));
$conv_meta = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $conv_meta[(int)$row['conversation_id']] = $row;
}

// 4. Decrypt + filter in PHP
$needle = mb_strtolower($q);
$results = [];
$per_conversation = [];
$max_per_conv = 5;
$max_total    = 50;

foreach ($messages as $m) {
    if (count($results) >= $max_total) break;

    $conv_id = (int)$m['conversation_id'];
    if (($per_conversation[$conv_id] ?? 0) >= $max_per_conv) continue;

    $plain = crypto_decrypt($m['body']);
    if ($plain === '' || $plain === false) continue;

    if (mb_stripos($plain, $needle) === false) continue;

    $pos    = mb_stripos($plain, $needle);
    $start  = max(0, $pos - 40);
    $snippet = mb_substr($plain, $start, 160);
    if ($start > 0) $snippet = '…' . $snippet;
    if (mb_strlen($plain) > $start + 160) $snippet .= '…';

    $meta  = $conv_meta[$conv_id] ?? null;
    $title = '';
    if ($meta) {
        if ((int)$meta['is_group'] === 1) {
            $title = $meta['group_name'] ?: 'Group chat';
        } else {
            $title = $meta['other_display_name'] ?: ($meta['other_username'] ?: 'Conversation');
        }
    }

    $results[] = [
        'message_id'      => (int)$m['id'],
        'conversation_id' => $conv_id,
        'conversation'    => $title,
        'sender_id'       => (int)$m['sender_id'],
        'sender_name'     => $m['sender_display_name'] ?: $m['sender_username'],
        'snippet'         => $snippet,
        'created_at'      => $m['created_at'],
    ];

    $per_conversation[$conv_id] = ($per_conversation[$conv_id] ?? 0) + 1;
}

echo json_encode([
    'success' => true,
    'query'   => $q,
    'count'   => count($results),
    'results' => $results,
]);