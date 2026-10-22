<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';

// Use the same TOTP class as the mobile app
require_once __DIR__ . '/includes/QarotaTOTP.php'; 

// Must have a pending 2FA login
$pendingUserId = (int)($_SESSION['pending_2fa_user_id'] ?? 0);
$pendingStart  = (int)($_SESSION['pending_2fa_started'] ?? 0);

if ($pendingUserId <= 0 || $pendingStart <= 0 || (time() - $pendingStart) > 300) {
    // Expired or missing — clear pending state, back to login
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_started'], $_SESSION['pending_2fa_remember']);
    header('Location: login.php');
    exit;
}

// Load the pending user (FIXED COLUMN NAMES)
$stmt = $pdo->prepare("SELECT id, username, display_name, two_factor_secret FROM users WHERE id = :id AND two_factor_enabled = 1 LIMIT 1");
$stmt->execute([':id' => $pendingUserId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_started'], $_SESSION['pending_2fa_remember']);
    header('Location: login.php');
    exit;
}

$error = '';
$useBackup = isset($_GET['backup']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    rate_limit_enforce($pdo, client_ip(), 'login_2fa', 10, 900);

    $mode = $_POST['mode'] ?? 'totp';
    $code = trim($_POST['code'] ?? '');

    $authenticated = false;

    if ($mode === 'totp') {
        $totp = new QarotaTOTP();
        // Verify the code using the exact same class as the mobile app
        if ($totp->verifyCode((string)$user['two_factor_secret'], $code, 2)) {
            $authenticated = true;
        } else {
            $error = 'That code is not correct. Try the next one your app shows.';
        }
    } elseif ($mode === 'backup') {
        // Try to match a backup code
        // Note: You need to make sure the totp_backup_codes table exists in your database.
        $normalized = str_replace('-', '', $code); // Simple normalization

        $stmt = $pdo->prepare("
            SELECT id, code_hash FROM totp_backup_codes
            WHERE user_id = :id AND used_at IS NULL
            ORDER BY id ASC
        ");
        $stmt->execute([':id' => $pendingUserId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            if (password_verify($normalized, $row['code_hash'])) {
                // Mark used
                $pdo->prepare("UPDATE totp_backup_codes SET used_at = NOW() WHERE id = :id")
                    ->execute([':id' => (int)$row['id']]);
                $authenticated = true;
                break;
            }
        }

        if (!$authenticated) {
            $error = 'That backup code is invalid or already used.';
        }
    }

    if ($authenticated) {
        // Full login
        $_SESSION['user_id'] = $pendingUserId;
        $_SESSION['username'] = $user['username'];
        $_SESSION['last_activity'] = time();
        $_SESSION['session_started'] = time();

        unset(
            $_SESSION['pending_2fa_user_id'],
            $_SESSION['pending_2fa_started'],
            $_SESSION['pending_2fa_remember']
        );

        regenerate_session_on_login();

        // Redirect to the feed (or profile, depending on your app)
        header('Location: feed.php'); 
        exit;
    }
}

$page_title = 'Two-factor verification';
require 'includes/header.php';
?>

<div class="container" style="max-width: 480px;">
    <div class="card">
        <h2 style="margin-top:0;">Verify your identity</h2>
        <p style="color: var(--muted); font-size: 0.9rem;">
            Welcome back, <strong><?= htmlspecialchars($user['display_name'] ?: $user['username']) ?></strong>.
            <?php if ($useBackup): ?>
                Enter one of your backup codes.
            <?php else: ?>
                Enter the 6-digit code from your authenticator app.
            <?php endif; ?>
        </p>

        <?php if ($error): ?>
            <div class="message" style="border-left-color: var(--danger); background: #FDECEA;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="<?= $useBackup ? 'backup' : 'totp' ?>">

            <div class="form-field">
                <label for="code">
                    <?= $useBackup ? 'Backup code' : '6-digit code' ?>
                </label>
                <input type="text"
                       id="code"
                       name="code"
                       inputmode="<?= $useBackup ? 'text' : 'numeric' ?>"
                       <?= $useBackup ? 'maxlength="9"' : 'pattern="[0-9]{6}" maxlength="6"' ?>
                       autocomplete="one-time-code"
                       autofocus
                       required
                       placeholder="<?= $useBackup ? 'xxxx-xxxx' : '000000' ?>">
            </div>

            <button type="submit">Verify and sign in</button>
        </form>

        <p style="text-align:center; margin-top: var(--space-4); font-size: 0.85rem;">
            <?php if ($useBackup): ?>
                <a href="login_2fa.php">Use your authenticator app instead</a>
            <?php else: ?>
                <a href="login_2fa.php?backup=1">Use a backup code instead</a>
            <?php endif; ?>
        </p>

        <p style="text-align:center; margin-top: var(--space-3); font-size: 0.8rem; color: var(--muted);">
            <a href="logout.php">Cancel and return to login</a>
        </p>
    </div>
</div>

<?php require 'includes/footer.php'; ?>