<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/blocks.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$me = (int)$_SESSION['user_id'];
$blocked = list_blocked($pdo, $me);

$page_title = __('blocked_users_title');
require 'includes/header.php';
?>

<h1><?= __('blocked_users_title') ?></h1>

<p style="color: var(--muted); margin-bottom: var(--space-5);">
    <?= __('blocked_users_intro') ?>
</p>

<?php if (empty($blocked)): ?>
    <div class="empty"><?= __('blocked_users_empty') ?></div>
<?php else: ?>
    <?php foreach ($blocked as $u): ?>
        <?php $name = $u['display_name'] ?: $u['username']; ?>
        <div class="user-row">
            <div style="display:flex; align-items:center; gap: var(--space-3);">
                <?php if (!empty($u['avatar'])): ?>
                    <img class="avatar avatar-small" src="<?= htmlspecialchars($u['avatar']) ?>" alt="">
                <?php else: ?>
                    <div class="avatar avatar-small avatar-placeholder">
                        <?= htmlspecialchars(mb_strtoupper(mb_substr($name, 0, 1))) ?>
                    </div>
                <?php endif; ?>
                <div>
                    <div class="name">
                        <a href="profile.php?u=<?= urlencode($u['username']) ?>">
                            <?= htmlspecialchars($name) ?>
                        </a>
                    </div>
                    <div style="font-size:0.8rem; color: var(--muted);">
                        @<?= htmlspecialchars($u['username']) ?>
                    </div>
                </div>
            </div>

            <form method="POST" action="block.php" class="block-form">
                <?= csrf_field() ?>
                <input type="hidden" name="target_id" value="<?= (int)$u['id'] ?>">
                <input type="hidden" name="block_action" value="unblock">
                <input type="hidden" name="redirect" value="blocked_users.php">
                <button type="submit" class="btn-secondary">
                    <?= __('block_unblock') ?>
                </button>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>