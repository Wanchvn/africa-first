<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT
        users.id, users.username, users.avatar,
        (SELECT 1 FROM follows WHERE follower_id = :me_follow AND following_id = users.id) AS is_following
    FROM users
    WHERE users.id != :me
    ORDER BY users.created_at DESC
");
$stmt->execute([
    ':me' => $user_id,
    ':me_follow' => $user_id,
]);
$users = $stmt->fetchAll();

$page_title = __('discover_title');
require 'includes/header.php';
?>

<h1><?= __('discover_title') ?></h1>

<?php if (empty($users)): ?>
    <div class="empty"><?= __('discover_empty') ?></div>
<?php else: ?>
    <?php foreach ($users as $u): ?>
        <div class="user-row">
            <div style="display:flex; align-items:center; gap:12px;">
                <?php if ($u['avatar']): ?>
                    <img class="avatar avatar-small"
                         src="<?= htmlspecialchars($u['avatar']) ?>"
                         alt="">
                <?php else: ?>
                    <div class="avatar avatar-small avatar-placeholder">
                        <?= strtoupper(substr($u['username'], 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <a href="profile.php?id=<?= $u['id'] ?>" class="name">
                    <?= htmlspecialchars($u['username']) ?>
                </a>
            </div>
            <form method="POST" action="follow.php">
                <?= csrf_field() ?>
                <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                <input type="hidden" name="redirect" value="discover.php">
                <button type="submit" class="<?= $u['is_following'] ? 'btn-secondary' : '' ?>">
                    <?= $u['is_following'] ? __('profile_unfollow') : __('profile_follow') ?>
                </button>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>