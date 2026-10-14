<?php
/**
 * Qarota — user data export.
 *
 * Produces a ZIP archive containing all of a user's data:
 * profile, posts, comments, likes, bookmarks, follows, messages,
 * notifications, moderation history, and media files.
 */

require_once __DIR__ . '/crypto.php';

/**
 * Build the export ZIP for a user.
 *
 * @param PDO $pdo
 * @param int $userId
 * @return array{path:string, filename:string, size:int}
 * @throws RuntimeException on failure
 */
function build_user_export(PDO $pdo, int $userId): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP zip extension is not enabled. Enable extension=zip in php.ini.');
    }

    $projectRoot = dirname(__DIR__);
    $exportsRoot = $projectRoot . '/storage/exports';

    if (!is_dir($exportsRoot) && !mkdir($exportsRoot, 0750, true) && !is_dir($exportsRoot)) {
        throw new RuntimeException('Could not create the exports directory.');
    }

    $token    = bin2hex(random_bytes(16));
    $filename = 'qarota-export-' . $userId . '-' . date('Ymd-His') . '.zip';
    $zipPath  = $exportsRoot . '/' . $token . '.zip';

    // ---- Load user ----
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new RuntimeException('User not found.');
    }
    unset($user['password_hash'], $user['password'], $user['totp_secret']);

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the export archive.');
    }

    try {
        // ---- Profile ----
        $profile = [
            'exported_at'         => date('c'),
            'export_format'       => '1.0',
            'account'             => [
                'id'            => (int)$user['id'],
                'username'      => $user['username'],
                'display_name'  => $user['display_name'],
                'bio'           => $user['bio'],
                'location'      => $user['location'],
                'occupation'    => $user['occupation'],
                'education'     => $user['education'],
                'languages'     => $user['languages'],
                'interests'     => $user['interests'],
                'avatar'        => $user['avatar'],
                'created_at'    => $user['created_at'] ?? null,
                'verification'  => $user['verification_type'] ?? 'none',
                'verified_at'   => $user['verified_at'] ?? null,
            ],
        ];
        $zip->addFromString('profile.json', json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // ---- Posts ----
        $stmt = $pdo->prepare("
            SELECT id, content, lang, media_path, media_type, media_duration, media_mime,
                   visibility, created_at, edited_at
            FROM posts
            WHERE user_id = :uid
            ORDER BY created_at ASC
        ");
        $stmt->execute([':uid' => $userId]);
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $postIdsForMedia = [];
        foreach ($posts as $p) {
            if (!empty($p['media_path'])) {
                $postIdsForMedia[] = (int)$p['id'];
            }
        }

        $zip->addFromString('posts.json', json_encode($posts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // ---- Comments ----
        $stmt = $pdo->prepare("
            SELECT id, post_id, content, created_at, edited_at
            FROM comments
            WHERE user_id = :uid
            ORDER BY created_at ASC
        ");
        $stmt->execute([':uid' => $userId]);
        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $zip->addFromString('comments.json', json_encode($comments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // ---- Likes ----
        $stmt = $pdo->prepare("
            SELECT post_id, created_at
            FROM likes
            WHERE user_id = :uid
            ORDER BY created_at ASC
        ");
        $stmt->execute([':uid' => $userId]);
        $likes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $zip->addFromString('likes.json', json_encode($likes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // ---- Bookmarks ----
        $stmt = $pdo->prepare("
            SELECT b.post_id, b.folder_id, bf.name AS folder_name, b.created_at
            FROM bookmarks b
            LEFT JOIN bookmark_folders bf ON bf.id = b.folder_id
            WHERE b.user_id = :uid
            ORDER BY b.created_at ASC
        ");
        $stmt->execute([':uid' => $userId]);
        $bookmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $zip->addFromString('bookmarks.json', json_encode($bookmarks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // ---- Follows (both directions) ----
        $stmt = $pdo->prepare("
            SELECT 'following' AS direction, u.username, u.display_name, f.created_at
            FROM follows f
            INNER JOIN users u ON u.id = f.following_id
            WHERE f.follower_id = :uid
            UNION ALL
            SELECT 'follower' AS direction, u.username, u.display_name, f.created_at
            FROM follows f
            INNER JOIN users u ON u.id = f.follower_id
            WHERE f.following_id = :uid
            ORDER BY created_at ASC
        ");
        $stmt->execute([':uid' => $userId]);
        $follows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $zip->addFromString('follows.json', json_encode($follows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // ---- Messages (all messages in conversations the user is a member of) ----
        $stmt = $pdo->prepare("
            SELECT DISTINCT cm.conversation_id
            FROM conversation_members cm
            WHERE cm.user_id = :uid
        ");
        $stmt->execute([':uid' => $userId]);
        $conversationIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $conversations = [];
        if (!empty($conversationIds)) {
            $ph = implode(',', array_fill(0, count($conversationIds), '?'));

            // Conversation metadata
            $stmt = $pdo->prepare("
                SELECT c.id, c.is_group, c.group_name, c.created_at,
                       (SELECT COUNT(*) FROM conversation_members WHERE conversation_id = c.id) AS member_count
                FROM conversations c
                WHERE c.id IN ($ph)
                ORDER BY c.id ASC
            ");
            $stmt->execute($conversationIds);
            $convs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Participants per conversation
            $stmt = $pdo->prepare("
                SELECT cm.conversation_id, u.username, u.display_name
                FROM conversation_members cm
                INNER JOIN users u ON u.id = cm.user_id
                WHERE cm.conversation_id IN ($ph)
                ORDER BY cm.conversation_id, cm.joined_at ASC
            ");
            $stmt->execute($conversationIds);
            $participantsByConv = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $participantsByConv[(int)$row['conversation_id']][] = [
                    'username'     => $row['username'],
                    'display_name' => $row['display_name'],
                ];
            }

            // Messages per conversation
            $stmt = $pdo->prepare("
                SELECT m.id, m.conversation_id, m.sender_id, m.body, m.message_type,
                       m.created_at, m.deleted_at,
                       u.username AS sender_username
                FROM messages m
                INNER JOIN users u ON u.id = m.sender_id
                WHERE m.conversation_id IN ($ph)
                ORDER BY m.conversation_id, m.id ASC
            ");
            $stmt->execute($conversationIds);
            $messagesByConv = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $plain = $m['body'] !== null ? crypto_decrypt($m['body']) : null;
                $messagesByConv[(int)$m['conversation_id']][] = [
                    'id'              => (int)$m['id'],
                    'sender_username' => $m['sender_username'],
                    'body'            => $plain,
                    'message_type'    => $m['message_type'],
                    'created_at'      => $m['created_at'],
                    'deleted_at'      => $m['deleted_at'],
                ];
            }

            // Build conversation records
            foreach ($convs as $c) {
                $cid = (int)$c['id'];
                $conversations[] = [
                    'id'           => $cid,
                    'is_group'     => (int)$c['is_group'] === 1,
                    'group_name'   => $c['group_name'],
                    'member_count' => (int)$c['member_count'],
                    'created_at'   => $c['created_at'],
                    'participants' => $participantsByConv[$cid] ?? [],
                    'messages'     => $messagesByConv[$cid] ?? [],
                ];
            }
        }

        $zip->addFromString('messages.json', json_encode($conversations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // ---- Notifications ----
        $stmt = $pdo->prepare("
            SELECT n.id, n.type, n.post_id, n.conversation_id, n.is_read, n.created_at,
                   u.username AS actor_username
            FROM notifications n
            LEFT JOIN users u ON u.id = n.actor_id
            WHERE n.user_id = :uid
            ORDER BY n.created_at ASC
        ");
        $stmt->execute([':uid' => $userId]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $zip->addFromString('notifications.json', json_encode($notifications, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // ---- Moderation history (bans, appeals, verifications) ----
        $stmt = $pdo->prepare("
            SELECT 'ban' AS kind, action_type, admin_note, created_at
            FROM moderation_log
            WHERE target_user_id = :uid OR (action_type IN ('user_banned','user_unbanned','user_verified','user_unverified')
                                            AND admin_note LIKE :uname_like)
            ORDER BY created_at ASC
        ");
        $stmt->execute([':uid' => $userId, ':uname_like' => '%@' . $user['username'] . '%']);
        $moderation = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Appeals by this user
        $stmt = $pdo->prepare("
            SELECT id, ban_reason, appeal_text, status, admin_reply, resolved_at, created_at
            FROM appeals
            WHERE user_id = :uid
            ORDER BY created_at ASC
        ");
        $stmt->execute([':uid' => $userId]);
        $appeals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Verification applications
        $stmt = $pdo->prepare("
            SELECT id, status, category, full_legal_name, country, id_type,
                   reason, resolved_at, created_at
            FROM verification_applications
            WHERE user_id = :uid
            ORDER BY created_at ASC
        ");
        $stmt->execute([':uid' => $userId]);
        $verifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $zip->addFromString('moderation.json', json_encode([
            'actions_involving_you' => $moderation,
            'appeals_you_filed'     => $appeals,
            'verification_requests' => $verifications,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // ---- Media files ----
        // Avatar
        if (!empty($user['avatar'])) {
            add_file_to_zip($zip, $projectRoot, $user['avatar'], 'media/avatar/');
        }

        // Post media (images, voice notes)
        foreach ($postIdsForMedia as $pid) {
            $stmt = $pdo->prepare("SELECT media_path, media_type FROM posts WHERE id = :id");
            $stmt->execute([':id' => $pid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['media_path'])) {
                $folder = 'media/' . ($row['media_type'] === 'voice' ? 'voice_notes/' : 'images/');
                add_file_to_zip($zip, $projectRoot, $row['media_path'], $folder);
            }
        }

        // ---- README ----
        $readme = build_export_readme($user);
        $zip->addFromString('README.txt', $readme);

        $zip->close();
    } catch (Throwable $e) {
        @$zip->close();
        @unlink($zipPath);
        throw $e;
    }

    if (!is_file($zipPath)) {
        throw new RuntimeException('Export file was not created.');
    }

    return [
        'path'     => $zipPath,
        'filename' => $filename,
        'size'     => filesize($zipPath),
    ];
}

/**
 * Add a file to the ZIP if it exists and is inside the project.
 */
function add_file_to_zip(ZipArchive $zip, string $projectRoot, string $relativePath, string $zipFolder): void
{
    $abs = $projectRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $abs = realpath($abs);
    if (!$abs || !is_file($abs)) return;

    $projectReal = realpath($projectRoot);
    if (!$projectReal || strpos($abs, $projectReal . DIRECTORY_SEPARATOR) !== 0) {
        return;   // path escapes project root
    }

    $basename = basename($abs);
    $zip->addFile($abs, rtrim($zipFolder, '/') . '/' . $basename);
}

/**
 * Build the plain-text README that ships inside the archive.
 */
function build_export_readme(array $user): string
{
    $name = $user['display_name'] ?: $user['username'];
    return "QAROTA DATA EXPORT\n" .
           "===================\n\n" .
           "Account: @{$user['username']} ({$name})\n" .
           "Exported: " . date('Y-m-d H:i:s') . " (UTC)\n\n" .
           "This archive contains everything Qarota holds about your account:\n\n" .
           "  profile.json           — your profile fields and account metadata\n" .
           "  posts.json             — every post you've ever made\n" .
           "  comments.json          — every comment you've left\n" .
           "  likes.json             — every post you've liked\n" .
           "  bookmarks.json         — your saved posts and folders\n" .
           "  follows.json           — who you follow, and who follows you\n" .
           "  messages.json          — your conversations and their messages\n" .
           "  notifications.json     — every notification you've received\n" .
           "  moderation.json        — bans, appeals, and verification requests\n" .
           "  media/                 — your avatar, images, and voice notes\n\n" .
           "Message bodies and profile data are decrypted here in plain text\n" .
           "so the archive is readable. If you share this file with anyone,\n" .
           "they will be able to read all of your private conversations.\n\n" .
           "Your data was never sold, shared, or transferred outside Ghana.\n\n" .
           "If you have questions:\n" .
           "  https://qarota.com/privacy.php\n\n" .
           "— The Qarota team\n";
}