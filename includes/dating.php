<?php
/**
 * Qarota — Dating helpers
 *
 * Phase 1: profile storage, retrieval, and search.
 * Phase 2: likes, matches, and swipe logic.
 */

/**
 * Fetch the current user's dating profile, or null if they haven't created one.
 */
function get_dating_profile(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM dating_profiles WHERE user_id = :u LIMIT 1");
    $stmt->execute([':u' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Has the user opted in (is_active = 1)?
 */
function is_dating_active(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare("SELECT is_active FROM dating_profiles WHERE user_id = :u LIMIT 1");
    $stmt->execute([':u' => $userId]);
    return (int)$stmt->fetchColumn() === 1;
}

/**
 * Create or update a user's dating profile.
 */
function save_dating_profile(PDO $pdo, int $userId, array $data): bool
{
    $age        = isset($data['age']) ? (int)$data['age'] : 0;
    $gender     = trim((string)($data['gender'] ?? ''));
    $interested = trim((string)($data['interested_in'] ?? ''));
    $looking    = trim((string)($data['looking_for'] ?? ''));
    $location   = trim((string)($data['location'] ?? ''));
    $occupation = trim((string)($data['occupation'] ?? ''));
    $education  = trim((string)($data['education'] ?? ''));
    $bio        = trim((string)($data['bio'] ?? ''));
    $interests  = trim((string)($data['interests'] ?? ''));
    $isActive   = !empty($data['is_active']) ? 1 : 0;

    if ($isActive) {
        if ($age < 18 || $age > 99) {
            throw new InvalidArgumentException('You must be 18 or older to use dating.');
        }
        if (!in_array($gender, ['man', 'woman', 'nonbinary', 'other'], true)) {
            throw new InvalidArgumentException('Please select your gender.');
        }
        if (!in_array($interested, ['men', 'women', 'everyone'], true)) {
            throw new InvalidArgumentException('Please select who you are interested in.');
        }
        if (!in_array($looking, ['relationship', 'marriage', 'friendship', 'unsure'], true)) {
            throw new InvalidArgumentException('Please select what you are looking for.');
        }
        if ($location === '') {
            throw new InvalidArgumentException('Please enter your location.');
        }
    }

    if (mb_strlen($bio) > 2000) {
        throw new InvalidArgumentException('Bio must be 2000 characters or less.');
    }
    if (mb_strlen($interests) > 500) {
        throw new InvalidArgumentException('Interests must be 500 characters or less.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO dating_profiles
            (user_id, is_active, age, gender, interested_in, looking_for,
             location, occupation, education, bio, interests)
        VALUES
            (:u, :a, :age, :g, :i, :l, :loc, :occ, :edu, :bio, :int)
        ON DUPLICATE KEY UPDATE
            is_active = VALUES(is_active),
            age = VALUES(age),
            gender = VALUES(gender),
            interested_in = VALUES(interested_in),
            looking_for = VALUES(looking_for),
            location = VALUES(location),
            occupation = VALUES(occupation),
            education = VALUES(education),
            bio = VALUES(bio),
            interests = VALUES(interests)
    ");
    $stmt->execute([
        ':u'   => $userId,
        ':a'   => $isActive,
        ':age' => $age ?: null,
        ':g'   => $gender ?: null,
        ':i'   => $interested ?: null,
        ':l'   => $looking ?: null,
        ':loc' => $location ?: null,
        ':occ' => $occupation ?: null,
        ':edu' => $education ?: null,
        ':bio' => $bio ?: null,
        ':int' => $interests ?: null,
    ]);
    return true;
}

/**
 * Search active dating profiles.
 */
function search_dating_profiles(PDO $pdo, int $viewerId, array $filters = [], int $limit = 60, int $offset = 0): array
{
    $sql = "
        SELECT dp.*,
               u.username, u.display_name, u.avatar, u.verification_type
        FROM dating_profiles dp
        INNER JOIN users u ON u.id = dp.user_id
        WHERE dp.is_active = 1
          AND dp.user_id <> :viewer
          AND u.is_banned = 0
    ";
    $params = [':viewer' => $viewerId];

    $sql .= "
          AND NOT EXISTS (
              SELECT 1 FROM user_blocks b
              WHERE (b.blocker_id = :viewer AND b.blocked_id = dp.user_id)
                 OR (b.blocker_id = dp.user_id AND b.blocked_id = :viewer)
          )
    ";

    if (!empty($filters['min_age']) && (int)$filters['min_age'] > 0) {
        $sql .= " AND dp.age >= :min_age";
        $params[':min_age'] = (int)$filters['min_age'];
    }
    if (!empty($filters['max_age']) && (int)$filters['max_age'] > 0) {
        $sql .= " AND dp.age <= :max_age";
        $params[':max_age'] = (int)$filters['max_age'];
    }
    if (!empty($filters['location'])) {
        $sql .= " AND dp.location LIKE :loc";
        $params[':loc'] = '%' . $filters['location'] . '%';
    }
    if (!empty($filters['looking_for']) && in_array($filters['looking_for'], ['relationship', 'marriage', 'friendship', 'unsure'], true)) {
        $sql .= " AND dp.looking_for = :lf";
        $params[':lf'] = $filters['looking_for'];
    }
    if (!empty($filters['interested_in']) && in_array($filters['interested_in'], ['men', 'women', 'everyone'], true)) {
        $sql .= " AND dp.interested_in = :ii";
        $params[':ii'] = $filters['interested_in'];
    }

    $sql .= " ORDER BY dp.updated_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch another user's active dating profile by username.
 */
function get_dating_profile_by_username(PDO $pdo, string $username, int $viewerId): ?array
{
    $stmt = $pdo->prepare("
        SELECT dp.*,
               u.id AS uid, u.username, u.display_name, u.avatar,
               u.verification_type, u.is_banned, u.bio AS user_bio
        FROM users u
        LEFT JOIN dating_profiles dp ON dp.user_id = u.id
        WHERE u.username = :username
          AND u.is_banned = 0
        LIMIT 1
    ");
    $stmt->execute([':username' => $username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) return null;
    if ((int)($row['is_active'] ?? 0) !== 1) return null;
    if ((int)$row['uid'] === $viewerId) return null;

    $b = $pdo->prepare("
        SELECT 1 FROM user_blocks
        WHERE (blocker_id = :v AND blocked_id = :t)
           OR (blocker_id = :t AND blocked_id = :v)
        LIMIT 1
    ");
    $b->execute([':v' => $viewerId, ':t' => (int)$row['uid']]);
    if ($b->fetchColumn()) return null;

    return $row;
}

function dating_looking_label(string $value): string
{
    return [
        'relationship' => 'A relationship',
        'marriage'     => 'Marriage',
        'friendship'   => 'Friendship',
        'unsure'       => 'Not sure yet',
    ][$value] ?? 'Unspecified';
}

function dating_gender_label(string $value): string
{
    return [
        'man'       => 'Man',
        'woman'     => 'Woman',
        'nonbinary' => 'Non-binary',
        'other'     => 'Other',
    ][$value] ?? 'Unspecified';
}

function dating_interested_label(string $value): string
{
    return [
        'men'      => 'Men',
        'women'    => 'Women',
        'everyone' => 'Everyone',
    ][$value] ?? 'Unspecified';
}

/* ============================================================
   PHASE 2 — SWIPES & MATCHES
   ============================================================ */

const DATING_DAILY_LIKE_LIMIT = 50;

function dating_likes_today(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM dating_likes
        WHERE from_user_id = :u
          AND action = 'like'
          AND created_at > (NOW() - INTERVAL 24 HOUR)
    ");
    $stmt->execute([':u' => $userId]);
    return (int)$stmt->fetchColumn();
}

function record_swipe(PDO $pdo, int $from, int $to, string $action): array
{
    if (!in_array($action, ['like', 'pass'], true)) {
        throw new InvalidArgumentException('Invalid action.');
    }
    if ($from === $to) {
        throw new InvalidArgumentException('Cannot swipe yourself.');
    }

    $pdo->beginTransaction();
    try {
        $lockUser = $pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE');
        foreach ([min($from, $to), max($from, $to)] as $userId) {
            $lockUser->execute([':id' => $userId]);
            if (!$lockUser->fetchColumn()) {
                throw new InvalidArgumentException('User not found.');
            }
        }

        $blocked = $pdo->prepare("
            SELECT 1 FROM user_blocks
            WHERE (blocker_id = :from_id AND blocked_id = :to_id)
               OR (blocker_id = :to_id AND blocked_id = :from_id)
            LIMIT 1
        ");
        $blocked->execute([':from_id' => $from, ':to_id' => $to]);
        if ($blocked->fetchColumn()) {
            throw new DomainException('Profile not available.');
        }

        $check = $pdo->prepare("
            SELECT action FROM dating_likes
            WHERE from_user_id = :f AND to_user_id = :t
            LIMIT 1
            FOR UPDATE
        ");
        $check->execute([':f' => $from, ':t' => $to]);
        $existing = $check->fetchColumn();
        if ($existing === 'pass' || ($existing !== false && $action === 'pass')) {
            $pdo->commit();
            return ['matched' => false, 'match' => null];
        }

        if ($existing === false) {
            if ($action === 'like' && dating_likes_today($pdo, $from) >= DATING_DAILY_LIKE_LIMIT) {
                throw new RuntimeException("You have reached today's like limit. Try again tomorrow.");
            }

            $ins = $pdo->prepare("
                INSERT INTO dating_likes (from_user_id, to_user_id, action)
                VALUES (:f, :t, :a)
            ");
            $ins->execute([':f' => $from, ':t' => $to, ':a' => $action]);
        }

        $matched = false;
        $matchRow = null;

        if ($action === 'like') {
            $recip = $pdo->prepare("
                SELECT action FROM dating_likes
                WHERE from_user_id = :t AND to_user_id = :f AND action = 'like'
                LIMIT 1
                FOR UPDATE
            ");
            $recip->execute([':t' => $to, ':f' => $from]);

            if ($recip->fetchColumn() === 'like') {
                $matched = true;
                $a = min($from, $to);
                $b = max($from, $to);

                $existingMatch = $pdo->prepare("
                    SELECT id, conversation_id FROM dating_matches
                    WHERE user_a_id = :a AND user_b_id = :b LIMIT 1
                    FOR UPDATE
                ");
                $existingMatch->execute([':a' => $a, ':b' => $b]);
                $m = $existingMatch->fetch(PDO::FETCH_ASSOC);

                if ($m) {
                    $matchRow = $m;
                } else {
                    $insMatch = $pdo->prepare("
                        INSERT INTO dating_matches (user_a_id, user_b_id)
                        VALUES (:a, :b)
                    ");
                    $insMatch->execute([':a' => $a, ':b' => $b]);
                    $matchRow = [
                        'id' => (int)$pdo->lastInsertId(),
                        'conversation_id' => null,
                    ];
                }

                $createdConversationMessage = false;
                require_once __DIR__ . '/messages.php';
                require_once __DIR__ . '/crypto.php';
                if (empty($matchRow['conversation_id'])) {
                    try {
                        $convId = get_or_create_conversation($pdo, $from, $to);
                        $pdo->prepare("
                            UPDATE dating_matches
                            SET conversation_id = :c
                            WHERE id = :id
                        ")->execute([':c' => $convId, ':id' => $matchRow['id']]);
                        $matchRow['conversation_id'] = $convId;

                        $sysBody = 'You matched on Qarota Dating. Say hello! 👋';
                        $enc = crypto_encrypt($sysBody);
                        $pdo->prepare("
                            INSERT INTO messages (conversation_id, sender_id, body, message_type)
                            VALUES (:c, :s, :b, 'system')
                        ")->execute([
                            ':c' => $convId,
                            ':s' => $from,
                            ':b' => $enc,
                        ]);
                        $createdConversationMessage = true;
                    } catch (Throwable $e) {
                        error_log('dating match conversation error: ' . $e->getMessage());
                    }
                }

                if ($createdConversationMessage) {
                    try {
                        notify_new_message($pdo, $to, $from, (int)($matchRow['conversation_id'] ?? 0));
                    } catch (Throwable $e) {
                        error_log('dating notify error: ' . $e->getMessage());
                    }
                }
            }
        }

        $pdo->commit();
        return ['matched' => $matched, 'match' => $matchRow];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function fetch_swipe_candidate(PDO $pdo, int $viewerId): ?array
{
    $stmt = $pdo->prepare("
        SELECT dp.user_id, dp.age, dp.gender, dp.interested_in, dp.looking_for,
               dp.location, dp.occupation, dp.education, dp.bio, dp.interests,
               u.username, u.display_name, u.avatar, u.verification_type
        FROM dating_profiles dp
        INNER JOIN users u ON u.id = dp.user_id
        LEFT JOIN dating_likes dl
          ON dl.from_user_id = :viewer AND dl.to_user_id = dp.user_id
        WHERE dp.is_active = 1
          AND dp.user_id <> :viewer
          AND u.is_banned = 0
          AND dl.id IS NULL
          AND NOT EXISTS (
              SELECT 1 FROM user_blocks b
              WHERE (b.blocker_id = :viewer AND b.blocked_id = dp.user_id)
                 OR (b.blocker_id = dp.user_id AND b.blocked_id = :viewer)
          )
        ORDER BY dp.updated_at DESC
        LIMIT 1
    ");
    $stmt->execute([':viewer' => $viewerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function list_dating_matches(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT dm.id AS match_id, dm.conversation_id, dm.matched_at,
               CASE WHEN dm.user_a_id = :u THEN dm.user_b_id ELSE dm.user_a_id END AS other_id,
               u.username, u.display_name, u.avatar
        FROM dating_matches dm
        INNER JOIN users u
          ON u.id = CASE WHEN dm.user_a_id = :u THEN dm.user_b_id ELSE dm.user_a_id END
        WHERE dm.is_active = 1
          AND (dm.user_a_id = :u OR dm.user_b_id = :u)
        ORDER BY dm.matched_at DESC
    ");
    $stmt->execute([':u' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_dating_match(PDO $pdo, int $matchId, int $viewerId): ?array
{
    $stmt = $pdo->prepare("
        SELECT dm.*, 
               CASE WHEN dm.user_a_id = :u THEN dm.user_b_id ELSE dm.user_a_id END AS other_id
        FROM dating_matches dm
        WHERE dm.id = :id
          AND dm.is_active = 1
          AND (dm.user_a_id = :u OR dm.user_b_id = :u)
        LIMIT 1
    ");
    $stmt->execute([':id' => $matchId, ':u' => $viewerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}





/* ============================================================
   PHASE 3 — PHOTOS & VERIFICATION
   ============================================================ */

const DATING_PHOTO_LIMIT = 6;

/**
 * List the user's gallery photos, ordered by position.
 */
function list_dating_photos(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT id, path, position
        FROM dating_photos
        WHERE user_id = :u
        ORDER BY position ASC, id ASC
    ");
    $stmt->execute([':u' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch a single photo row by id, verifying ownership.
 */
function get_dating_photo(PDO $pdo, int $photoId, int $userId): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, user_id, path, position
        FROM dating_photos
        WHERE id = :id AND user_id = :u
        LIMIT 1
    ");
    $stmt->execute([':id' => $photoId, ':u' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Fetch the first gallery photo of a user (used on cards and profiles).
 */
function get_primary_dating_photo(PDO $pdo, int $userId): ?string
{
    $stmt = $pdo->prepare("
        SELECT path FROM dating_photos
        WHERE user_id = :u
        ORDER BY position ASC, id ASC
        LIMIT 1
    ");
    $stmt->execute([':u' => $userId]);
    $path = $stmt->fetchColumn();
    return $path ?: null;
}

/**
 * Add a photo record. Enforces the 6-photo limit.
 */
function add_dating_photo(PDO $pdo, int $userId, string $relativePath): int
{
    $count = (int)$pdo->query("SELECT COUNT(*) FROM dating_photos WHERE user_id = " . (int)$userId)->fetchColumn();
    if ($count >= DATING_PHOTO_LIMIT) {
        throw new RuntimeException('You already have ' . DATING_PHOTO_LIMIT . ' photos. Delete one first.');
    }

    $nextPos = (int)$pdo->query("SELECT COALESCE(MAX(position), -1) + 1 FROM dating_photos WHERE user_id = " . (int)$userId)->fetchColumn();

    $ins = $pdo->prepare("
        INSERT INTO dating_photos (user_id, path, position)
        VALUES (:u, :p, :pos)
    ");
    $ins->execute([':u' => $userId, ':p' => $relativePath, ':pos' => $nextPos]);
    return (int)$pdo->lastInsertId();
}

/**
 * Delete a photo and its file from disk. Verifies ownership.
 */
function delete_dating_photo(PDO $pdo, int $photoId, int $userId): bool
{
    $photo = get_dating_photo($pdo, $photoId, $userId);
    if (!$photo) return false;

    $abs = dirname(__DIR__) . '/' . $photo['path'];
    if (is_file($abs)) @unlink($abs);

    $pdo->prepare("DELETE FROM dating_photos WHERE id = :id AND user_id = :u")
        ->execute([':id' => $photoId, ':u' => $userId]);
    return true;
}

/**
 * Get the user's current verification status. Returns the most recent row
 * (pending, approved, or rejected), or null if they've never submitted.
 */
function get_dating_verification(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare("
        SELECT * FROM dating_verification
        WHERE user_id = :u
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([':u' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Is the user currently verified (approved and no newer rejected/pending row)?
 */
function is_dating_verified(PDO $pdo, int $userId): bool
{
    $v = get_dating_verification($pdo, $userId);
    return $v && $v['status'] === 'approved';
}

/**
 * Generate a fresh random code for verification.
 */
function generate_dating_code(): string
{
    // 4 digits, zero-padded. Easy to write on paper and photograph.
    return str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
}

/**
 * Submit a selfie for verification. Replaces any pending row.
 */
function submit_dating_verification(PDO $pdo, int $userId, string $code, string $relativePath): int
{
    // Remove any earlier pending row for this user so they can retry.
    $pdo->prepare("
        DELETE FROM dating_verification
        WHERE user_id = :u AND status = 'pending'
    ")->execute([':u' => $userId]);

    $ins = $pdo->prepare("
        INSERT INTO dating_verification (user_id, code, selfie_path, status, submitted_at)
        VALUES (:u, :c, :p, 'pending', NOW())
    ");
    $ins->execute([':u' => $userId, ':c' => $code, ':p' => $relativePath]);
    return (int)$pdo->lastInsertId();
}

/**
 * Fetch a single verification row (for admin view or serving the selfie).
 */
function get_dating_verification_by_id(PDO $pdo, int $verId): ?array
{
    $stmt = $pdo->prepare("
        SELECT v.*, u.username, u.display_name, u.avatar
        FROM dating_verification v
        INNER JOIN users u ON u.id = v.user_id
        WHERE v.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $verId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * List pending verifications for the admin queue.
 */
function list_pending_dating_verifications(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT v.*, u.username, u.display_name, u.avatar
        FROM dating_verification v
        INNER JOIN users u ON u.id = v.user_id
        WHERE v.status = 'pending'
        ORDER BY v.submitted_at ASC
        LIMIT 100
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}