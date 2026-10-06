<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$post_id = (int)($_GET['post_id'] ?? 0);
$comment_id = (int)($_GET['comment_id'] ?? 0);

if ($post_id <= 0 && $comment_id <= 0) {
    header('Location: feed.php');
    exit;
}

if ($post_id > 0) {
    $stmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = :id");
    $stmt->execute([':id' => $post_id]);
    $owner = $stmt->fetchColumn();
    if ($owner === false || (int)$owner === $user_id) {
        header('Location: feed.php');
        exit;
    }
}

if ($comment_id > 0) {
    $stmt = $pdo->prepare("SELECT user_id FROM comments WHERE id = :id");
    $stmt->execute([':id' => $comment_id]);
    $owner = $stmt->fetchColumn();
    if ($owner === false || (int)$owner === $user_id) {
        header('Location: feed.php');
        exit;
    }
}

$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    rate_limit_enforce($pdo, client_ip(), 'report', 10, 3600);

    $reason = $_POST['reason'] ?? '';
    $details = trim($_POST['details'] ?? '');

    $allowed_reasons = ['spam', 'harassment', 'violence', 'nudity', 'misinformation', 'other'];

    if (!in_array($reason, $allowed_reasons, true)) {
        $errors[] = __('report_error_reason');
    }

    if (mb_strlen($details) > 1000) {
        $errors[] = __('report_error_length');
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            SELECT id FROM reports
            WHERE reporter_id = :me
              AND status = 'pending'
              AND (
                (post_id IS NOT NULL AND post_id = :post_id)
                OR (comment_id IS NOT NULL AND comment_id = :comment_id)
              )
        ");
        $stmt->execute([
            ':me' => $user_id,
            ':post_id' => $post_id > 0 ? $post_id : null,
            ':comment_id' => $comment_id > 0 ? $comment_id : null,
        ]);

        if ($stmt->fetch()) {
            $errors[] = __('report_error_duplicate');
        }
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            INSERT INTO reports (reporter_id, post_id, comment_id, reason, details)
            VALUES (:me, :post_id, :comment_id, :reason, :details)
        ");
        $stmt->execute([
            ':me' => $user_id,
            ':post_id' => $post_id > 0 ? $post_id : null,
            ':comment_id' => $comment_id > 0 ? $comment_id : null,
            ':reason' => $reason,
            ':details' => $details !== '' ? $details : null,
        ]);

        $message = __('report_success');
    }
}

$page_title = __('report_title');
require 'includes/header.php';
?>

<h1><?= __('report_title') ?></h1>

<?php if (!empty($errors)): ?>
    <div class="message" style="border-left-color: #c0392b; background: #FDECEA;">
        <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($message): ?>
    <div class="message"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if (empty($message)): ?>
    <div class="card">
        <form method="POST" class="stack">
            <?= csrf_field() ?>
            <label for="reason"><?= __('report_reason') ?></label>
            <select id="reason" name="reason" required>
                <option value=""><?= __('report_reason') ?>...</option>
                <option value="spam">Spam or scam</option>
                <option value="harassment">Harassment or bullying</option>
                <option value="violence">Violence or threats</option>
                <option value="nudity">Nudity or sexual content</option>
                <option value="misinformation">Misinformation</option>
                <option value="other">Other</option>
            </select>

            <label for="details"><?= __('report_details') ?></label>
            <textarea id="details" name="details" maxlength="1000"
                      placeholder="Tell us what's wrong. Be specific."></textarea>

            <button type="submit" class="btn-danger"><?= __('report_submit') ?></button>
        </form>
    </div>
<?php endif; ?>

<p><a href="feed.php">← <?= __('nav_feed') ?></a></p>

<?php require 'includes/footer.php'; ?>