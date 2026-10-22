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
if (!is_dating_verified($pdo, $me)) {
    $_SESSION['flash_error'] = 'Dating verification must be approved before using Dating.';
    header('Location: dating_setup.php');
    exit;
}

$iAmActive = is_dating_active($pdo, $me);

$filters = [
    'min_age'     => (int)($_GET['min_age'] ?? 0),
    'max_age'     => (int)($_GET['max_age'] ?? 0),
    'location'    => trim((string)($_GET['location'] ?? '')),
    'looking_for' => trim((string)($_GET['looking_for'] ?? '')),
];

$results = [];
$total   = 0;

if ($iAmActive) {
    $results = search_dating_profiles($pdo, $me, $filters, 60, 0);
    $total   = count($results);
}

$page_title = 'Browse — Dating';
require 'includes/header.php';
?>

<div class="dating-container">
    <div class="dating-header">
        <h1>Browse profiles</h1>
        <p class="dating-subtitle">
            All active members, filtered your way. Swipe on the deck for one-at-a-time.
        </p>
    </div>

    <div class="dating-mode-tabs">
        <a href="dating.php">Swipe</a>
        <a href="dating_browse.php" class="active">Browse</a>
        <a href="dating_matches.php">Matches</a>
    </div>

    <?php if (!$iAmActive): ?>
        <div class="card dating-inactive-card">
            <h2 style="margin-top:0;">Set up your dating profile first</h2>
            <p style="color:var(--muted);">
                Only active members can browse. It takes about two minutes to set up.
            </p>
            <a href="dating_setup.php" class="btn">Set up my profile</a>
        </div>
    <?php else: ?>

        <form method="GET" action="dating_browse.php" class="dating-filters card">
            <div class="dating-filters-grid">
                <div class="form-field">
                    <label for="min_age">Age from</label>
                    <input type="number" id="min_age" name="min_age" min="18" max="99"
                           value="<?= $filters['min_age'] ?: '' ?>" placeholder="18">
                </div>
                <div class="form-field">
                    <label for="max_age">Age to</label>
                    <input type="number" id="max_age" name="max_age" min="18" max="99"
                           value="<?= $filters['max_age'] ?: '' ?>" placeholder="99">
                </div>
                <div class="form-field">
                    <label for="location">Location</label>
                    <input type="text" id="location" name="location"
                           value="<?= htmlspecialchars($filters['location']) ?>"
                           placeholder="Anywhere">
                </div>
                <div class="form-field">
                    <label for="looking_for">Looking for</label>
                    <select id="looking_for" name="looking_for">
                        <option value="">Any</option>
                        <?php foreach (['relationship' => 'A relationship', 'marriage' => 'Marriage', 'friendship' => 'Friendship', 'unsure' => 'Not sure yet'] as $v => $lbl): ?>
                            <option value="<?= $v ?>" <?= $filters['looking_for'] === $v ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="margin-top: var(--space-3); display:flex; gap: var(--space-2); flex-wrap: wrap;">
                <button type="submit">Apply filters</button>
                <a href="dating_browse.php" class="btn-secondary">Reset</a>
                <a href="dating_setup.php" class="btn-secondary" style="margin-left:auto;">Edit my profile</a>
            </div>
        </form>

        <?php if ($total === 0): ?>
            <div class="empty">
                No profiles match your filters yet.
                <br>
                <a href="dating_browse.php" style="color:var(--terracotta);">Clear filters</a>
            </div>
        <?php else: ?>
            <p style="color:var(--muted); margin-bottom:var(--space-3);">
                <?= $total ?> <?= $total === 1 ? 'person' : 'people' ?> found
            </p>
            <div class="dating-grid">
                <?php foreach ($results as $r): ?>
                    <?php $name = $r['display_name'] ?: $r['username']; ?>
                    <a href="dating_profile.php?u=<?= urlencode($r['username']) ?>" class="dating-card">
                        <div class="dating-card-avatar-wrap">
                            <?php if (!empty($r['avatar'])): ?>
                                <img class="dating-card-avatar" src="<?= htmlspecialchars($r['avatar']) ?>" alt="">
                            <?php else: ?>
                                <div class="dating-card-avatar dating-card-avatar-placeholder">
                                    <?= htmlspecialchars(mb_strtoupper(mb_substr($name, 0, 1))) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="dating-card-name">
                            <?= htmlspecialchars($name) ?><?php if (!empty($r['age'])): ?>, <?= (int)$r['age'] ?><?php endif; ?>
                        </div>
                        <?php if (!empty($r['location'])): ?>
                            <div class="dating-card-location">
                                <i data-lucide="map-pin" style="width:12px;height:12px;vertical-align:middle;"></i>
                                <?= htmlspecialchars($r['location']) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($r['looking_for'])): ?>
                            <div class="dating-card-looking">
                                <?= htmlspecialchars(dating_looking_label($r['looking_for'])) ?>
                            </div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require 'includes/footer.php'; ?>