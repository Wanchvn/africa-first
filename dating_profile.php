<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/blocks.php';
require_once __DIR__ . '/includes/dating.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$me = (int)$_SESSION['user_id'];

if (!is_dating_active($pdo, $me)) {
    $_SESSION['flash_error'] = 'Set up your dating profile to view other members.';
    header('Location: dating.php');
    exit;
}

$username = trim((string)($_GET['u'] ?? ''));
if ($username === '') {
    header('Location: dating.php');
    exit;
}

$p = get_dating_profile_by_username($pdo, $username, $me);
if (!$p) {
    http_response_code(404);
    $page_title = 'Profile not found';
    require 'includes/header.php';
    echo '<div class="card"><div class="empty">This dating profile isn\'t available.</div></div>';
    require 'includes/footer.php';
    exit;
}

$name = $p['display_name'] ?: $p['username'];

// Phase 3 data
$galleryPhotos = list_dating_photos($pdo, (int)$p['uid']);
$isVerified    = is_dating_verified($pdo, (int)$p['uid']);

$page_title = $name . ' — Dating';
require 'includes/header.php';
?>

<div class="dating-container">
    <p style="margin-top:0;">
        <a href="dating.php">← Back to browsing</a>
    </p>

    <div class="card dating-profile-card">
        <div class="dating-profile-head">
            <?php if (!empty($p['avatar'])): ?>
                <img class="dating-profile-avatar" src="<?= htmlspecialchars($p['avatar']) ?>" alt="">
            <?php else: ?>
                <div class="dating-profile-avatar dating-card-avatar-placeholder">
                    <?= htmlspecialchars(mb_strtoupper(mb_substr($name, 0, 1))) ?>
                </div>
            <?php endif; ?>

            <div class="dating-profile-head-info">
                <h1 style="margin:0 0 4px;">
                    <?= htmlspecialchars($name) ?><?php if (!empty($p['age'])): ?>, <?= (int)$p['age'] ?><?php endif; ?>
                    <?php if ($isVerified): ?>
                        <span class="verified-pill" title="Verified">✓</span>
                    <?php endif; ?>
                </h1>
                <div class="dating-profile-handle">@<?= htmlspecialchars($p['username']) ?></div>

                <?php if ($isVerified): ?>
                    <div style="color:var(--baobab-green); font-weight:600; font-size:0.85rem; margin-top:2px;">
                        ✓ Verified profile
                    </div>
                <?php endif; ?>

                <?php if (!empty($p['location'])): ?>
                    <div class="dating-profile-detail">
                        <i data-lucide="map-pin" style="width:14px;height:14px;vertical-align:middle;"></i>
                        <?= htmlspecialchars($p['location']) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($p['occupation'])): ?>
                    <div class="dating-profile-detail">
                        <i data-lucide="briefcase" style="width:14px;height:14px;vertical-align:middle;"></i>
                        <?= htmlspecialchars($p['occupation']) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($p['education'])): ?>
                    <div class="dating-profile-detail">
                        <i data-lucide="graduation-cap" style="width:14px;height:14px;vertical-align:middle;"></i>
                        <?= htmlspecialchars($p['education']) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($galleryPhotos)): ?>
            <div class="dating-photo-gallery">
                <?php foreach ($galleryPhotos as $g): ?>
                    <a href="dating_photo_serve.php?id=<?= (int)$g['id'] ?>" target="_blank" rel="noopener">
                        <img src="dating_photo_serve.php?id=<?= (int)$g['id'] ?>" alt="">
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="dating-profile-meta">
            <div>
                <strong>Looking for:</strong>
                <?= htmlspecialchars(dating_looking_label((string)$p['looking_for'])) ?>
            </div>
            <div>
                <strong>Interested in:</strong>
                <?= htmlspecialchars(dating_interested_label((string)$p['interested_in'])) ?>
            </div>
            <?php if (!empty($p['interests'])): ?>
                <div>
                    <strong>Interests:</strong>
                    <?= htmlspecialchars($p['interests']) ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($p['bio'])): ?>
            <div class="dating-profile-bio">
                <?= nl2br(htmlspecialchars($p['bio'])) ?>
            </div>
        <?php endif; ?>

        <div class="dating-profile-actions">
            <a href="start_conversation.php?user=<?= (int)$p['uid'] ?>" class="btn">
                <i data-lucide="mail" style="width:16px;height:16px;vertical-align:middle;"></i>
                Send a message
            </a>
            <a href="profile.php?u=<?= urlencode($p['username']) ?>" class="btn-secondary">
                View full profile
            </a>
            <a href="report.php?user_id=<?= (int)$p['uid'] ?>" class="btn-secondary">
                Report
            </a>
            <form method="POST" action="block.php" class="block-form" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="target_id" value="<?= (int)$p['uid'] ?>">
                <input type="hidden" name="block_action" value="block">
                <input type="hidden" name="redirect" value="dating.php">
                <button type="submit" class="btn-secondary">Block</button>
            </form>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>