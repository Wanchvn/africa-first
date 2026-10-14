<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require_once __DIR__ . '/includes/posts.php';
require_once __DIR__ . '/includes/crypto.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// ---- CSRF + rate limit (POST only for building) ----
$downloadToken = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');

// Serve a previously-built export (one-shot download)
if ($downloadToken !== '') {
    $zipPath = __DIR__ . '/storage/exports/' . $downloadToken . '.zip';
    if (!is_file($zipPath)) {
        http_response_code(404);
        exit('Export not found or expired.');
    }

    $username = preg_replace('/[^a-zA-Z0-9_-]/', '', $_SESSION['username'] ?? 'user');
    $filename = 'qarota_export_' . $username . '_' . date('Y-m-d') . '.zip';

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-store');
    readfile($zipPath);

    @unlink($zipPath);
    exit;
}

if($_SERVER["REQUEST_METHOD"]=== "POST")
{ csrf_verify();
rate_limit_enforce($pdo, client_ip(), 'data_export', 3, 86400);
}
// ---- Cleanup: delete exports older than 24 hours ----
$exportsDir = __DIR__ . '/storage/exports';
if (!is_dir($exportsDir)) {
    @mkdir($exportsDir, 0750, true);
}
if (is_dir($exportsDir)) {
    $cutoff = time() - 86400;
    foreach (scandir($exportsDir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $p = $exportsDir . '/' . $entry;
        if (is_file($p) && filemtime($p) < $cutoff) {
            @unlink($p);
        }
    }
}

$error = '';
$downloadUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The PHP zip extension is not enabled. Enable extension=zip in php.ini.');
        }

        // ---- Load user (without secrets) ----
        $stmt = $pdo->prepare("
            SELECT id, username, display_name, email, avatar, bio,
                   location, occupation, education, languages, interests,
                   verification_type, verified_at, created_at
            FROM users
            WHERE id = :id
        ");
        $stmt->execute([':id' => $user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new RuntimeException('User not found.');
        }

        // ---- Build the ZIP in storage/exports/ ----
        $projectRoot = __DIR__;
        $token       = bin2hex(random_bytes(16));
        $zipPath     = $exportsDir . '/' . $token . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the export archive.');
        }

        try {
            // --- Profile ---
            $zip->addFromString('profile.json', json_encode([
                'exported_at'   => date('c'),
                'export_format' => '2.0',
                'account'       => $user,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // --- Posts ---
            $stmt = $pdo->prepare("
                SELECT id, content, lang, media_path, media_type, media_duration, media_mime,
                       visibility, created_at, edited_at
                FROM posts
                WHERE user_id = :id
                ORDER BY created_at ASC
            ");
            $stmt->execute([':id' => $user_id]);
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $zip->addFromString('posts.json', json_encode($posts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // --- Comments ---
            $export_comment_visibility_sql = post_visibility_filter('posts', 'export_comment');
            $stmt = $pdo->prepare("
                SELECT comments.id, comments.post_id, comments.content, comments.created_at, comments.edited_at,
                       CASE WHEN $export_comment_visibility_sql THEN posts.content ELSE NULL END AS post_content,
                       posts.user_id AS post_author
                FROM comments
                INNER JOIN posts ON comments.post_id = posts.id
                WHERE comments.user_id = :id
                ORDER BY comments.created_at ASC
            ");
            $stmt->execute(array_merge(
                [':id' => $user_id],
                post_visibility_params($user_id, 'export_comment')
            ));
            $zip->addFromString('comments.json', json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // --- Likes ---
            $export_like_visibility_sql = post_visibility_filter('posts', 'export_like');
            $stmt = $pdo->prepare("
                SELECT likes.id, likes.post_id, likes.created_at,
                       CASE WHEN $export_like_visibility_sql THEN posts.content ELSE NULL END AS post_content,
                       posts.user_id AS post_author
                FROM likes
                INNER JOIN posts ON likes.post_id = posts.id
                WHERE likes.user_id = :id
                ORDER BY likes.created_at ASC
            ");
            $stmt->execute(array_merge(
                [':id' => $user_id],
                post_visibility_params($user_id, 'export_like')
            ));
            $zip->addFromString('likes.json', json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // --- Bookmarks ---
            $stmt = $pdo->prepare("
                SELECT b.post_id, b.folder_id, bf.name AS folder_name, b.created_at
                FROM bookmarks b
                LEFT JOIN bookmark_folders bf ON bf.id = b.folder_id
                WHERE b.user_id = :id
                ORDER BY b.created_at ASC
            ");
            $stmt->execute([':id' => $user_id]);
            $zip->addFromString('bookmarks.json', json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // --- Following ---
            $stmt = $pdo->prepare("
                SELECT u.id, u.username, u.display_name, f.created_at
                FROM follows f
                INNER JOIN users u ON f.following_id = u.id
                WHERE f.follower_id = :id
                ORDER BY f.created_at ASC
            ");
            $stmt->execute([':id' => $user_id]);
            $zip->addFromString('following.json', json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // --- Followers ---
            $stmt = $pdo->prepare("
                SELECT u.id, u.username, u.display_name, f.created_at
                FROM follows f
                INNER JOIN users u ON f.follower_id = u.id
                WHERE f.following_id = :id
                ORDER BY f.created_at ASC
            ");
            $stmt->execute([':id' => $user_id]);
            $zip->addFromString('followers.json', json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // --- Notifications ---
            $stmt = $pdo->prepare("
                SELECT n.id, n.type, n.post_id, n.conversation_id, n.is_read, n.created_at,
                       u.username AS actor_username
                FROM notifications n
                LEFT JOIN users u ON u.id = n.actor_id
                WHERE n.user_id = :id
                ORDER BY n.created_at ASC
            ");
            $stmt->execute([':id' => $user_id]);
            $zip->addFromString('notifications.json', json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // --- Direct messages (decrypted, grouped by conversation) ---
            $stmt = $pdo->prepare("
                SELECT conversation_id FROM conversation_members WHERE user_id = :id
            ");
            $stmt->execute([':id' => $user_id]);
            $conversationIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            $conversations = [];
            if (!empty($conversationIds)) {
                $ph = implode(',', array_fill(0, count($conversationIds), '?'));

                $stmt = $pdo->prepare("
                    SELECT id, is_group, group_name, created_at
                    FROM conversations
                    WHERE id IN ($ph)
                    ORDER BY id ASC
                ");
                $stmt->execute($conversationIds);
                $convs = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $stmt = $pdo->prepare("
                    SELECT cm.conversation_id, u.username, u.display_name
                    FROM conversation_members cm
                    INNER JOIN users u ON u.id = cm.user_id
                    WHERE cm.conversation_id IN ($ph)
                    ORDER BY cm.conversation_id, cm.joined_at ASC
                ");
                $stmt->execute($conversationIds);
                $partsByConv = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $partsByConv[(int)$row['conversation_id']][] = [
                        'username'     => $row['username'],
                        'display_name' => $row['display_name'],
                    ];
                }

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
                $msgsByConv = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
                    $plain = $m['body'] !== null ? crypto_decrypt($m['body']) : null;
                    $msgsByConv[(int)$m['conversation_id']][] = [
                        'id'              => (int)$m['id'],
                        'sender_username' => $m['sender_username'],
                        'body'            => $plain,
                        'message_type'    => $m['message_type'],
                        'created_at'      => $m['created_at'],
                        'deleted_at'      => $m['deleted_at'],
                    ];
                }

                foreach ($convs as $c) {
                    $cid = (int)$c['id'];
                    $conversations[] = [
                        'id'           => $cid,
                        'is_group'     => (int)$c['is_group'] === 1,
                        'group_name'   => $c['group_name'],
                        'created_at'   => $c['created_at'],
                        'participants' => $partsByConv[$cid] ?? [],
                        'messages'     => $msgsByConv[$cid] ?? [],
                    ];
                }
            }
            $zip->addFromString('messages.json', json_encode($conversations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // --- Moderation + appeals + verification ---
            $stmt = $pdo->prepare("
                SELECT action_type, admin_note, created_at
                FROM moderation_log
                WHERE admin_note LIKE :uname_like
                ORDER BY created_at ASC
            ");
            $stmt->execute([':uname_like' => '%@' . $user['username'] . '%']);
            $moderation = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                SELECT id, ban_reason, appeal_text, status, admin_reply, resolved_at, created_at
                FROM appeals WHERE user_id = :id ORDER BY created_at ASC
            ");
            $stmt->execute([':id' => $user_id]);
            $appeals = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                SELECT id, status, category, full_legal_name, country, id_type,
                       reason, resolved_at, created_at
                FROM verification_applications WHERE user_id = :id ORDER BY created_at ASC
            ");
            $stmt->execute([':id' => $user_id]);
            $verifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $zip->addFromString('moderation.json', json_encode([
                'actions_involving_you' => $moderation,
                'appeals_you_filed'     => $appeals,
                'verification_requests' => $verifications,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // --- Media files ---
            $projectReal = realpath($projectRoot);

            // Post media
            foreach ($posts as $post) {
                if (empty($post['media_path'])) continue;
                $abs = realpath($projectRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $post['media_path']));
                if (!$abs || !is_file($abs) || strpos($abs, $projectReal . DIRECTORY_SEPARATOR) !== 0) continue;
                $folder = $post['media_type'] === 'voice' ? 'media/voice_notes/' : 'media/images/';
                $zip->addFile($abs, $folder . basename($abs));
            }

            // Avatar
            if (!empty($user['avatar'])) {
                $abs = realpath($projectRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $user['avatar']));
                if ($abs && is_file($abs) && strpos($abs, $projectReal . DIRECTORY_SEPARATOR) === 0) {
                    $zip->addFile($abs, 'media/avatar/' . basename($abs));
                }
            }

            // --- README ---
            $readme =
                "QAROTA DATA EXPORT\n" .
                "==================\n\n" .
                "Account: @{$user['username']}\n" .
                "Exported: " . date('Y-m-d H:i:s') . "\n\n" .
                "This archive contains everything Qarota holds about your account:\n\n" .
                "  profile.json        — your profile and account fields\n" .
                "  posts.json          — every post you've made\n" .
                "  comments.json       — every comment you've left\n" .
                "  likes.json          — every post you've liked\n" .
                "  bookmarks.json      — your saved posts and folders\n" .
                "  following.json      — who you follow\n" .
                "  followers.json      — who follows you\n" .
                "  messages.json       — your conversations (decrypted)\n" .
                "  notifications.json  — every notification you've received\n" .
                "  moderation.json     — bans, appeals, verification requests\n" .
                "  media/              — your avatar, images, and voice notes\n\n" .
                "Your conversations are stored encrypted on Qarota's servers.\n" .
                "This export decrypts them so you can read them. If you share\n" .
                "this ZIP with anyone, they will be able to read your private\n" .
                "messages.\n\n" .
                "Your data has never been sold, shared, or moved outside Ghana.\n\n" .
                "— The Qarota team\n";

            $zip->addFromString('README.txt', $readme);

            $zip->close();
        } catch (Throwable $e) {
            @$zip->close();
            @unlink($zipPath);
            throw $e;
        }

        $downloadUrl = 'export.php?token=' . urlencode($token);

    } catch (Throwable $e) {
        error_log('data export error: ' . $e->getMessage());
        $error = $e->getMessage();
    }
}

$page_title = 'Export your data';
require 'includes/header.php';
?>

<div class="container" style="max-width: 640px;">
    <h1>Export your data</h1>

    <?php if ($error): ?>
        <div class="message" style="border-left-color: var(--danger); background: #FDECEA;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($downloadUrl): ?>
        <div class="card" style="border-left: 4px solid var(--baobab-green);">
            <h2 style="margin-top:0;">Your export is ready</h2>
            <p>
                Everything Qarota holds about your account is in one ZIP archive.
                The download link works once and expires in 24 hours.
            </p>
            <p style="font-size: 0.85rem; color: var(--muted);">
                <strong>Keep it safe.</strong> The archive contains your private messages
                in plain text so you can read them. Don't share it with anyone you
                don't trust with your conversations.
            </p>
            <a href="<?= htmlspecialchars($downloadUrl) ?>" class="btn">
                <i data-lucide="download" style="width:16px;height:16px;vertical-align:middle;"></i>
                Download ZIP
            </a>
        </div>
    <?php else: ?>
        <div class="card">
            <h2 style="margin-top:0;">What you'll get</h2>
            <ul style="line-height:1.8; padding-left: var(--space-5);">
                <li>Your profile and account settings</li>
                <li>Every post you've made, with media files</li>
                <li>Your comments, likes, and bookmarks</li>
                <li>Who you follow, and who follows you</li>
                <li>Your conversations and message history</li>
                <li>Your notification history</li>
                <li>Your moderation and verification history</li>
            </ul>

            <p style="color: var(--muted); font-size: 0.9rem;">
                The archive is a ZIP file. Everything inside is readable JSON plus
                your original media files. Nothing is hidden or obfuscated.
            </p>

            <p style="color: var(--muted); font-size: 0.9rem;">
                <strong>One thing to know:</strong> messages are stored encrypted on our
                servers, but the export decrypts them so you can actually read them.
                That means the ZIP contains your private conversations in plain text.
                Treat it accordingly.
            </p>

            <form method="POST">
                <?= csrf_field() ?>
                <button type="submit">
                    <i data-lucide="package" style="width:16px;height:16px;vertical-align:middle;"></i>
                    Build my export
                </button>
            </form>
        </div>
    <?php endif; ?>

    <p style="text-align:center; font-size: 0.85rem; color: var(--muted); margin-top: var(--space-5);">
        Your data stays in Ghana. You can take it with you any time.
        <br>
        <a href="privacy.php">See our privacy commitments →</a>
    </p>
</div>

<?php require 'includes/footer.php'; ?>