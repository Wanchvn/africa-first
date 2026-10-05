<?php
session_start();
require 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$post_id = (int)($_GET['post_id'] ?? 0);
$comment_id = (int)($_GET['comment_id'] ?? 0);

// Must report either a post or a comment
if ($post_id <= 0 && $comment_id <= 0) {
    header('Location: feed.php');
    exit;
}

// Verify the target exists and get its owner (so you don't report yourself)
if ($post_id > 0) {
    $stmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = :id");
    $stmt->execute([':id' => $post_id]);
    $owner = $stmt->fetchColumn();
    if ($owner === false) {
        header('Location: feed.php');
        exit;
    }
    if ((int)$owner === $user_id) {
        // Can't report your own post
        header('Location: feed.php');
        exit;
    }
}

if ($comment_id > 0) {
    $stmt = $pdo->prepare("SELECT user_id FROM comments WHERE id = :id");
    $stmt->execute([':id' => $comment_id]);
    $owner = $stmt->fetchColumn();
    if ($owner === false) {
        header('Location: feed.php');
        exit;
    }
    if ((int)$owner === $user_id) {
        header('Location: feed.php');
        exit;
    }
}

$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = $_POST['reason'] ?? '';
    $details = trim($_POST['details'] ?? '');

    $allowed_reasons = ['spam', 'harassment', 'violence', 'nudity', 'misinformation', 'other'];

    if (!in_array($reason, $allowed_reasons, true)) {
        $errors[] = 'Please choose a reason.';
    }

    if (strlen($details) > 1000) {
        $errors[] = 'Details must be under 1000 characters.';
    }

    // Prevent duplicate pending reports from the same user on the same target
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
            $errors[] = 'You already have a pending report for this content.';
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

        $message = 'Report submitted. Our team will review it.';
    }
}

$page_title = 'Report Content';
require 'includes/header.php';
?>

<h1>Report content</h1>

<div class="card">
    <p>Help keep Qarota safe. Reports are reviewed by a human — never automated.</p>
    <p>You're reporting <?= $post_id > 0 ? 'a post' : 'a comment' ?>.</p>
</div>

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
            <label for="reason">Reason</label>
            <select id="reason" name="reason" required>
                <option value="">Choose a reason...</option>
                <option value="spam">Spam or scam</option>
                <option value="harassment">Harassment or bullying</option>
                <option value="violence">Violence or threats</option>
                <option value="nudity">Nudity or sexual content</option>
                <option value="misinformation">Misinformation</option>
                <option value="other">Other</option>
            </select>

            <label for="details">More details (optional)</label>
            <textarea id="details" name="details" maxlength="1000"
                      placeholder="Tell us what's wrong. Be specific."></textarea>

            <button type="submit" class="btn-danger">Submit report</button>
        </form>
    </div>
<?php endif; ?>

<p><a href="feed.php">← Back to feed</a></p>

<?php require 'includes/footer.php'; ?>