<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/blocks.php';

$user = api_require_admin($pdo);
$me = (int)$user['id'];

// Get my location and languages
$stmt = $pdo->prepare("SELECT location, languages FROM users WHERE id = :id");
$stmt->execute([':id' => $me]);
$me_info = $stmt->fetch(PDO::FETCH_ASSOC);
$my_location = trim($me_info['location'] ?? '');
$my_lang_tokens = array_filter(array_map('trim', explode(',', $me_info['languages'] ?? '')));

$suggestions = [];

// 1. Friends of friends
$stmt = $pdo->prepare("
    SELECT DISTINCT u.id, u.username, u.display_name, u.avatar, u.verification_type,
           'friends_of_friends' AS source
    FROM users u
    WHERE u.id != :me
      AND u.id NOT IN (SELECT following_id FROM follows WHERE follower_id = :me)
      AND u.id IN (SELECT following_id FROM follows WHERE follower_id IN (SELECT following_id FROM follows WHERE follower_id = :me))
      AND u.is_banned = 0
    LIMIT 5
");
$stmt->execute([':me' => $me]);
$suggestions = array_merge($suggestions, $stmt->fetchAll(PDO::FETCH_ASSOC));

// 2. Same location
if ($my_location !== '') {
    $stmt = $pdo->prepare("
        SELECT id, username, display_name, avatar, verification_type, 'same_location' AS source
        FROM users 
        WHERE id != :me 
          AND id NOT IN (SELECT following_id FROM follows WHERE follower_id = :me) 
          AND location = :loc
          AND is_banned = 0
        LIMIT 5
    ");
    $stmt->execute([':me' => $me, ':loc' => $my_location]);
    $suggestions = array_merge($suggestions, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

// 3. Shared language
if (!empty($my_lang_tokens)) {
    $lang_conditions = [];
    $lang_params = [':me' => $me];
    foreach ($my_lang_tokens as $i => $lang) {
        $key = ":lang$i";
        $lang_conditions[] = "languages LIKE $key";
        $lang_params[$key] = '%' . $lang . '%';
    }
    $lang_sql = implode(' OR ', $lang_conditions);
    
    $stmt = $pdo->prepare("
        SELECT id, username, display_name, avatar, verification_type, 'shared_language' AS source 
        FROM users 
        WHERE id != :me 
          AND id NOT IN (SELECT following_id FROM follows WHERE follower_id = :me) 
          AND ($lang_sql)
          AND is_banned = 0
        LIMIT 5
    ");
    $stmt->execute($lang_params);
    $suggestions = array_merge($suggestions, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

// Deduplicate
$seen = [];
$unique = [];
foreach ($suggestions as $s) {
    if (isset($seen[$s['id']])) continue;
    $seen[$s['id']] = true;
    $unique[] = [
        'id' => (int)$s['id'],
        'username' => $s['username'],
        'name' => $s['display_name'] ?: $s['username'],
        'avatar' => $s['avatar'],
        'is_verified' => ($s['verification_type'] ?? 'none') !== 'none',
        'reason' => $s['source']
    ];
}

api_ok(['suggestions' => array_slice($unique, 0, 10)]);