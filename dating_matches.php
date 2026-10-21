<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require_once __DIR__ . '/includes/dating.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$me = (int)$_SESSION['user_id'];
$matches = list_dating_matches($pdo, $me);

$page_title = 'Matches';
require 'includes/header.php';
?>

<div class="dating-container">
    <div class="dating-header">
        <h1>Matches</h1>
        <p class="dating-subtitle">People you've both liked. Say hello.</p>
    </div>

    <div class="dating-mode-tabs">
        <a href="dating.php">Swipe</a>
        <a href="dating_browse.php">Browse</a>
        <a href="dating_matches.php" class="active">Matches</a>
    </div>

    <?php if (empty($matches)): ?>
        <div class="empty">
            No matches yet. Keep swiping and someone will like you back.
        </div>
    <?php else: ?>
        <div class="match-grid">
            <?php foreach ($matches as $m): ?>
                <?php $name = $m['display_name'] ?: $m['username']; ?>
                <a href="conversation.php?id=<?= (int)$m['conversation_id'] ?>" class="match-tile">
                    <?php if (!empty($m['avatar'])): ?>
                        <img class="match-tile-avatar" src="<?= htmlspecialchars($m['avatar']) ?>" alt="">
                    <?php else: ?>
                        <div class="match-tile-avatar dating-card-avatar-placeholder">
                            <?= htmlspecialchars(mb_strtoupper(mb_substr($name, 0, 1))) ?>
                        </div>
                    <?php endif; ?>
                    <div class="match-tile-name"><?= htmlspecialchars($name) ?></div>
                    <div class="match-tile-date"><?= htmlspecialchars(substr($m['matched_at'], 0, 10)) ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require 'includes/footer.php'; ?>