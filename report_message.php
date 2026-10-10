<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require 'includes/messages.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$me          = (int) $_SESSION['user_id'];
$conversationId = isset($_GET['conversation_id']) ? (int) $_GET['conversation_id'] : 0;
$messageId      = isset($_GET['message_id'])      ? (int) $_GET['message_id']      : 0;

if ($conversationId <= 0 || !user_in_conversation($pdo, $conversationId, $me)) {
    header('Location: messages.php');
    exit;
}

// If reporting a specific message, verify it belongs to the conversation
// and was NOT sent by the reporter.
$targetMessage = null;
if ($messageId > 0) {
    $stmt = $pdo->prepare(
        "SELECT id, sender_id, body, created_at
         FROM messages
         WHERE id = :id AND conversation_id = :c LIMIT 1"
    );
    $stmt->execute([':id' => $messageId, ':c' => $conversationId]);
    $targetMessage = $stmt->fetch();

    if (!$targetMessage || (int)$targetMessage['sender_id'] === $me) {
        header('Location: conversation.php?id=' . $conversationId);
        exit;
    }
}

$partnerId = conversation_partner($pdo, $conversationId, $me);
$pStmt = $pdo->prepare("SELECT id, username, display_name FROM users WHERE id = :id LIMIT 1");
$pStmt->execute([':id' => $partnerId]);
$partner = $pStmt->fetch();
$partner_name = $partner ? ($partner['display_name'] ?: $partner['username']) : '';

$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    rate_limit_enforce($pdo, client_ip(), 'report', 5, 3600);

    $reason  = trim($_POST['reason'] ?? '');
    $details = trim($_POST['details'] ?? '');

    $allowed_reasons = ['harassment', 'spam', 'threats', 'hate', 'scam', 'other'];

    if (!in_array($reason, $allowed_reasons, true)) {
        $message = __('report_error_reason');
    } elseif (mb_strlen($details) > 1000) {
        $message = __('report_error_length');
    } else {
        // Prevent duplicate pending reports on the same target by this user
        if ($messageId > 0) {
            $dupe = $pdo->prepare(
                "SELECT 1 FROM reports
                 WHERE reporter_id = :me AND message_id = :mid AND status = 'pending'
                 LIMIT 1"
            );
            $dupe->execute([':me' => $me, ':mid' => $messageId]);
        } else {
            $dupe = $pdo->prepare(
                "SELECT 1 FROM reports
                 WHERE reporter_id = :me AND conversation_id = :cid
                   AND message_id IS NULL AND status = 'pending'
                 LIMIT 1"
            );
            $dupe->execute([':me' => $me, ':cid' => $conversationId]);
        }

        if ($dupe->fetchColumn()) {
            $message = __('report_error_duplicate');
        } else {
            $ins = $pdo->prepare(
                "INSERT INTO reports
                    (reporter_id, conversation_id, message_id, reason, details, status)
                 VALUES
                    (:reporter, :conv, :msg, :reason, :details, 'pending')"
            );
            $ins->execute([
                ':reporter' => $me,
                ':conv'     => $conversationId,
                ':msg'      => $messageId > 0 ? $messageId : null,
                ':reason'   => $reason,
                ':details'  => $details !== '' ? $details : null,
            ]);

            // Public moderation log entry — metadata only, never content
            $pdo->prepare(
                "INSERT INTO moderation_log (actor_id, action, target_type, target_id, note, created_at)
                 VALUES (:actor, 'reported', 'message', :tid, :note, NOW())"
            )->execute([
                ':actor' => $me,
                ':tid'   => $messageId > 0 ? $messageId : $conversationId,
                ':note'  => $messageId > 0 ? 'Reported a direct message' : 'Reported a conversation',
            ]);

            $success = true;
        }
    }
}

$page_title = __('report_title');
require 'includes/header.php';
?>

<div class="card">
    <h2 style="margin-top:0;"><?= __('report_title') ?></h2>

    <?php if ($success): ?>
        <div class="message">
            <?= __('report_success') ?>
        </div>
        <p style="margin-top: var(--space-4);">
            <a href="conversation.php?id=<?= (int)$conversationId ?>" class="btn-secondary">
                ← <?= __('messages_back_to_inbox') ?>
            </a>
        </p>
    <?php else: ?>

        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <p style="color: var(--muted); font-size: 0.9rem;">
            <?php if ($messageId > 0): ?>
                Reporting a message from <strong><?= htmlspecialchars($partner_name) ?></strong>:
            <?php else: ?>
                Reporting the conversation with <strong><?= htmlspecialchars($partner_name) ?></strong>.
            <?php endif; ?>
        </p>

        <?php if ($targetMessage): ?>
            <div class="report-preview">
                "<?= htmlspecialchars(mb_substr($targetMessage['body'], 0, 200)) ?><?= mb_strlen($targetMessage['body']) > 200 ? '…' : '' ?>"
                <div class="meta"><?= htmlspecialchars($targetMessage['created_at']) ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" class="stack" style="margin-top: var(--space-4);">
            <?= csrf_field() ?>

            <div class="form-field">
                <label for="reason"><?= __('report_reason') ?></label>
                <select id="reason" name="reason" required>
                    <option value="">— <?= __('report_reason') ?> —</option>
                    <option value="harassment">Harassment or bullying</option>
                    <option value="spam">Spam</option>
                    <option value="threats">Threats or violence</option>
                    <option value="hate">Hate speech</option>
                    <option value="scam">Scam or fraud</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <div class="form-field">
                <label for="details">
                    <?= __('report_details') ?>
                    <span class="optional">(optional)</span>
                </label>
                <textarea id="details" name="details" maxlength="1000" rows="4"
                          placeholder="<?= __('report_details') ?>"></textarea>
            </div>

            <button type="submit"><?= __('report_submit') ?></button>
        </form>
    <?php endif; ?>
</div>

<?php require 'includes/footer.php'; ?>