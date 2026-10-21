<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require_once __DIR__ . '/includes/dating.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$me = (int)$_SESSION['user_id'];
$iAmActive = is_dating_active($pdo, $me);

$candidate = null;
if ($iAmActive) {
    $candidate = fetch_swipe_candidate($pdo, $me);
}

$remainingLikes = $iAmActive ? max(0, DATING_DAILY_LIKE_LIMIT - dating_likes_today($pdo, $me)) : 0;

$page_title = 'Dating';
require 'includes/header.php';
?>

<div class="dating-container">
    <div class="dating-header">
        <h1>Dating</h1>
        <p class="dating-subtitle">
            Swipe right to like, left to pass. If they like you back, it's a match.
        </p>
    </div>

    <div class="dating-mode-tabs">
        <a href="dating.php" class="active">Swipe</a>
        <a href="dating_browse.php">Browse</a>
        <a href="dating_matches.php">Matches</a>
    </div>

    <?php if (!$iAmActive): ?>
        <div class="card dating-inactive-card">
            <h2 style="margin-top:0;">Set up your dating profile first</h2>
            <p style="color:var(--muted);">
                Only active members can swipe. It takes about two minutes to set up.
            </p>
            <a href="dating_setup.php" class="btn">Set up my profile</a>
        </div>
    <?php elseif ($remainingLikes <= 0): ?>
        <div class="card dating-inactive-card">
            <h2 style="margin-top:0;">Out of likes for today</h2>
            <p style="color:var(--muted);">
                You've sent <?= DATING_DAILY_LIKE_LIMIT ?> likes in the last 24 hours.
                Come back tomorrow for more, or
                <a href="dating_browse.php" style="color:var(--terracotta);">browse without liking</a>.
            </p>
        </div>
    <?php elseif (!$candidate): ?>
        <div class="card dating-inactive-card">
            <h2 style="margin-top:0;">You're all caught up</h2>
            <p style="color:var(--muted);">
                No new profiles to swipe right now. Check back later, or
                <a href="dating_browse.php" style="color:var(--terracotta);">browse everyone</a>.
            </p>
        </div>
    <?php else: ?>

        <p class="dating-likes-counter">
            <span id="likesRemaining"><?= $remainingLikes ?></span> likes left today
        </p>

        <div id="swipeDeck" class="swipe-deck"
             data-candidate-id="<?= (int)$candidate['user_id'] ?>"
             data-csrf="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

            <div class="swipe-card" id="swipeCard">
                <?php
// Prefer a dating gallery photo, fall back to avatar.
$primaryPhoto = get_primary_dating_photo($pdo, (int)$candidate['user_id']);
$photoUrl = $primaryPhoto
    ? 'dating_photo_serve.php?id=' . (int)explode(':', $primaryPhoto)[0]
    : '';
// Simpler: fetch the photo id directly.
$stmtPhoto = $pdo->prepare("SELECT id FROM dating_photos WHERE user_id = :u ORDER BY position ASC, id ASC LIMIT 1");
$stmtPhoto->execute([':u' => (int)$candidate['user_id']]);
$primaryPhotoId = (int)$stmtPhoto->fetchColumn();
?>
<div class="swipe-card-photo">
    <?php if ($primaryPhotoId > 0): ?>
        <img src="dating_photo_serve.php?id=<?= $primaryPhotoId ?>" alt="">
    <?php elseif (!empty($candidate['avatar'])): ?>
        <img src="<?= htmlspecialchars($candidate['avatar']) ?>" alt="">
    <?php else: ?>
        <div class="swipe-card-photo-placeholder">
            <?= htmlspecialchars(mb_strtoupper(mb_substr($candidate['display_name'] ?: $candidate['username'], 0, 1))) ?>
        </div>
    <?php endif; ?>
</div>

                <div class="swipe-card-info">
                    <?php $candVerified = is_dating_verified($pdo, (int)$candidate['user_id']); ?>
<div class="swipe-card-name">
    <?= htmlspecialchars($candidate['display_name'] ?: $candidate['username']) ?>
    <?php if (!empty($candidate['age'])): ?>, <?= (int)$candidate['age'] ?><?php endif; ?>
    <?php if ($candVerified): ?>
        <span class="verified-pill" title="Verified">✓</span>
    <?php endif; ?>
</div>
                    <?php if (!empty($candidate['location'])): ?>
                        <div class="swipe-card-location">
                            <i data-lucide="map-pin" style="width:13px;height:13px;vertical-align:middle;"></i>
                            <?= htmlspecialchars($candidate['location']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($candidate['looking_for'])): ?>
                        <div class="swipe-card-looking">
                            <?= htmlspecialchars(dating_looking_label($candidate['looking_for'])) ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($candidate['bio'])): ?>
                        <p class="swipe-card-bio"><?= htmlspecialchars(mb_strimwidth($candidate['bio'], 0, 220, '…')) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="swipe-actions">
                <button type="button" class="swipe-btn swipe-pass" data-action="pass" aria-label="Pass">
                    <i data-lucide="x" style="width:28px;height:28px;"></i>
                </button>
                <button type="button" class="swipe-btn swipe-like" data-action="like" aria-label="Like">
                    <i data-lucide="heart" style="width:28px;height:28px;"></i>
                </button>
            </div>

            <div class="swipe-hint">Swipe or use the buttons</div>
        </div>

        <div id="matchModal" class="match-modal" hidden>
            <div class="match-card">
                <div class="match-badge">🎉 It's a match!</div>
                <div class="match-avatar-row" id="matchAvatars"></div>
                <p class="match-message">You both liked each other.</p>
                <div class="match-actions">
                    <a id="matchMessageBtn" href="#" class="btn">Send a message</a>
                    <button type="button" id="matchKeepSwiping" class="btn-secondary">Keep swiping</button>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

<script src="assets/js/dating.js?v=<?= filemtime(__DIR__ . '/assets/js/dating.js') ?>" defer></script>
<?php require 'includes/footer.php'; ?>