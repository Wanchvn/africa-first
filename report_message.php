<?php
require_once __DIR__ . '/includes/bootstrap.php';
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
$hasMessageId = array_key_exists('message_id', $_GET);
$messageId = $hasMessageId ? filter_var($_GET['message_id'], FILTER_VALIDATE_INT) : 0;

if (
    $conversationId <= 0
    || ($hasMessageId && ($messageId === false || $messageId <= 0))
    || !user_in_conversation($pdo, $conversationId, $me)
) {
    header('Location: messages.php');
    exit;
}

// If reporting a specific message, verify it belongs to the conversation
// and was NOT sent by the reporter.
$targetMessage = null;
if ($messageId > 0) {
    $stmt = $pdo->prepare(
        "SELECT id, sender_id, body, message_type, created_at
         FROM messages
         WHERE id = :id AND conversation_id = :c LIMIT 1"
    );
    $stmt->execute([':id' => $messageId, ':c' => $conversationId]);
    $targetMessage = $stmt->fetch();
    if ($targetMessage) {
        require_once __DIR__ . '/includes/crypto.php';
        $targetMessage['body'] = crypto_decrypt($targetMessage['body']);
    }

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
    rate_limit_enforce($pdo, client_ip(), 'report_message', 5, 3600);

    $reason  = trim($_POST['reason'] ?? '');
    $details = trim($_POST['details'] ?? '');

    $allowed_reasons = ['harassment', 'spam', 'violence', 'nudity', 'misinformation', 'other'];

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
            $pdo->beginTransaction();
            try {
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

                $pdo->prepare(
                    "INSERT INTO moderation_log (action_type, created_at)
                     VALUES ('message_reported', NOW())"
                )->execute();

                $pdo->commit();
                $success = true;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
    }
}

$page_title = $messageId > 0 ? __('report_dm_title') : __('report_dm_conversation_title');
require 'includes/header.php';
?>

<div class="card">
    <h2 style="margin-top:0;"><?= htmlspecialchars($page_title) ?></h2>

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
                <?= sprintf(__('report_dm_message_from'), htmlspecialchars($partner_name, ENT_QUOTES, 'UTF-8')) ?>
            <?php else: ?>
                <?= sprintf(__('report_dm_conversation_with'), htmlspecialchars($partner_name, ENT_QUOTES, 'UTF-8')) ?>
            <?php endif; ?>
        </p>
        <p style="color: var(--muted); font-size: 0.9rem;">
            <?= __('report_dm_intro') ?>
        </p>

        <?php if ($targetMessage): ?>
            <div class="report-preview">
                <?php if ($targetMessage['body'] !== ''): ?>
                    "<?= htmlspecialchars(mb_substr($targetMessage['body'], 0, 200)) ?><?= mb_strlen($targetMessage['body']) > 200 ? '…' : '' ?>"
                <?php else: ?>
                    <?= __('report_message_attachment') ?>
                <?php endif; ?>
                <div class="meta"><?= htmlspecialchars($targetMessage['created_at']) ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" class="stack" style="margin-top: var(--space-4);">
            <?= csrf_field() ?>

            <div class="form-field">
                <label for="reason"><?= __('report_reason') ?></label>
                <select id="reason" name="reason" required>
                    <option value="">— <?= __('report_reason') ?> —</option>
                    <option value="harassment"><?= __('report_reason_harassment') ?></option>
                    <option value="spam"><?= __('report_reason_spam') ?></option>
                    <option value="violence"><?= __('report_reason_violence') ?></option>
                    <option value="nudity"><?= __('report_reason_nudity') ?></option>
                    <option value="misinformation"><?= __('report_reason_misinformation') ?></option>
                    <option value="other"><?= __('report_reason_other') ?></option>
                </select>
            </div>

            <div class="form-field">
                <label for="details">
                    <?= __('report_details') ?>
                    <span class="optional">(<?= __('report_optional') ?>)</span>
                </label>
                <textarea id="details" name="details" maxlength="1000" rows="4"
                          placeholder="<?= __('report_details') ?>"></textarea>
            </div>

            <button type="submit"><?= __('report_submit') ?></button>
        </form>
    <?php endif; ?>
</div>

<?php require 'includes/footer.php'; ?>