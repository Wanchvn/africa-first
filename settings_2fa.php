<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require_once __DIR__ . '/includes/totp.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Load current 2FA state
$stmt = $pdo->prepare("SELECT totp_secret, totp_enabled, totp_confirmed_at FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';
$step = 'overview';

// ---- Action handling ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    rate_limit_enforce($pdo, client_ip(), 'settings_2fa', 20, 3600);

    $action = $_POST['action'] ?? '';

    // --- Start setup: generate a secret, show QR ---
    if ($action === 'start_setup' && !(int)$user['totp_enabled']) {
        // Reuse pending secret if user re-enters setup
        if (!empty($user['totp_secret']) && !(int)$user['totp_enabled']) {
            $secret = $user['totp_secret'];
        } else {
            $secret = totp_generate_secret();
            $pdo->prepare("UPDATE users SET totp_secret = :s, totp_enabled = 0 WHERE id = :id")
                ->execute([':s' => $secret, ':id' => $user_id]);
        }

        $_SESSION['pending_2fa_secret'] = $secret;
        $step = 'confirm';
    }

    // --- Confirm: user submitted the first code ---
    elseif ($action === 'confirm_setup') {
        $secret = $_SESSION['pending_2fa_secret'] ?? null;
        $code   = trim($_POST['code'] ?? '');

        if (!$secret) {
            $error = 'Setup session expired. Please try again.';
            $step = 'overview';
        } elseif (!totp_verify($secret, $code)) {
            $error = 'That code is not correct. Make sure your phone clock is synced, then try the next code.';
            $step = 'confirm';
        } else {
            // Generate backup codes
            $backupCodes = totp_generate_backup_codes(10);

            $pdo->beginTransaction();
            try {
                $pdo->prepare("
                    UPDATE users
                    SET totp_enabled = 1, totp_confirmed_at = NOW()
                    WHERE id = :id
                ")->execute([':id' => $user_id]);

                // Delete any previous backup codes
                $pdo->prepare("DELETE FROM totp_backup_codes WHERE user_id = :id")->execute([':id' => $user_id]);

                // Insert new hashed codes
                $stmt = $pdo->prepare("INSERT INTO totp_backup_codes (user_id, code_hash) VALUES (:uid, :hash)");
                foreach ($backupCodes as $code) {
                    $stmt->execute([
                        ':uid'  => $user_id,
                        ':hash' => password_hash(totp_normalize_backup_code($code), PASSWORD_DEFAULT),
                    ]);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('2fa enable error: ' . $e->getMessage());
                $error = 'Could not enable 2FA. Please try again.';
                $step = 'overview';
            }

            if (empty($error)) {
                $_SESSION['fresh_backup_codes'] = $backupCodes;
                unset($_SESSION['pending_2fa_secret']);
                $success = '2FA is now enabled on your account.';
                $step = 'backup_codes';
                $user['totp_enabled'] = 1;
            }
        }
    }

    // --- Disable ---
    elseif ($action === 'disable') {
        $password = $_POST['password'] ?? '';
        $code     = trim($_POST['code'] ?? '');

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $user_id]);
        $hash = (string)$stmt->fetchColumn();

        if (!password_verify($password, $hash)) {
            $error = 'Incorrect password.';
        } elseif (!empty($user['totp_secret']) && !totp_verify((string)$user['totp_secret'], $code)) {
            $error = 'Incorrect code.';
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("
                    UPDATE users
                    SET totp_enabled = 0, totp_secret = NULL, totp_confirmed_at = NULL
                    WHERE id = :id
                ")->execute([':id' => $user_id]);

                $pdo->prepare("DELETE FROM totp_backup_codes WHERE user_id = :id")
                    ->execute([':id' => $user_id]);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('2fa disable error: ' . $e->getMessage());
                $error = 'Could not disable 2FA. Please try again.';
            }

            if (empty($error)) {
                $success = '2FA has been disabled.';
                $user['totp_enabled'] = 0;
                $user['totp_secret']  = null;
            }
        }
    }

    // --- Regenerate backup codes ---
    elseif ($action === 'regenerate_codes') {
        $password = $_POST['password'] ?? '';
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $user_id]);
        $hash = (string)$stmt->fetchColumn();

        if (!password_verify($password, $hash)) {
            $error = 'Incorrect password.';
        } else {
            $backupCodes = totp_generate_backup_codes(10);

            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM totp_backup_codes WHERE user_id = :id")->execute([':id' => $user_id]);
                $stmt = $pdo->prepare("INSERT INTO totp_backup_codes (user_id, code_hash) VALUES (:uid, :hash)");
                foreach ($backupCodes as $code) {
                    $stmt->execute([
                        ':uid'  => $user_id,
                        ':hash' => password_hash(totp_normalize_backup_code($code), PASSWORD_DEFAULT),
                    ]);
                }
                $pdo->commit();

                $_SESSION['fresh_backup_codes'] = $backupCodes;
                $success = 'New backup codes generated. The old ones no longer work.';
                $step = 'backup_codes';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Could not generate codes. Please try again.';
            }
        }
    }
}

// After a POST that lands on confirm, load the secret
$pendingSecret = $_SESSION['pending_2fa_secret'] ?? null;
$freshCodes    = $_SESSION['fresh_backup_codes'] ?? [];
unset($_SESSION['fresh_backup_codes']);

$page_title = 'Two-factor authentication';
require 'includes/header.php';
?>

<div class="container" style="max-width: 640px;">
    <h1>Two-factor authentication</h1>

    <?php if ($error): ?>
        <div class="message" style="border-left-color: var(--danger); background: #FDECEA;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="message" style="border-left-color: var(--baobab-green); background: #E8F5E9;">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php /* ============ BACKUP CODES SHOWN ONCE ============ */ ?>
    <?php if ($step === 'backup_codes' && !empty($freshCodes)): ?>
        <div class="card" style="border-left: 4px solid var(--terracotta);">
            <h2 style="margin-top:0;">Save your backup codes</h2>
            <p>
                These 10 codes are your only way to sign in if you lose access to
                your authenticator app. <strong>Save them somewhere safe right now.</strong>
                Each code works once.
            </p>

            <div style="display:grid; grid-template-columns: repeat(2, 1fr); gap: var(--space-2); font-family: 'SF Mono', Monaco, Consolas, monospace; padding: var(--space-4); background: var(--sand); border-radius: var(--radius-md); margin: var(--space-4) 0;">
                <?php foreach ($freshCodes as $code): ?>
                    <div><?= htmlspecialchars($code) ?></div>
                <?php endforeach; ?>
            </div>

            <p style="color: var(--muted); font-size: 0.85rem;">
                This is the only time we'll show these. They are stored as salted hashes
                on our servers — we can't recover or re-display them.
            </p>

            <a href="settings_2fa.php" class="btn" style="margin-top: var(--space-3);">
                I've saved my codes
            </a>
        </div>
    <?php endif; ?>

    <?php /* ============ CONFIRM SETUP ============ */ ?>
    <?php if ($step === 'confirm' && !empty($pendingSecret)): ?>
        <?php $uri = totp_otpauth_uri($pendingSecret, $_SESSION['username'] ?? 'user'); ?>
        <div class="card">
            <h2 style="margin-top:0;">Scan the QR code</h2>
            <p>
                Open Google Authenticator, Authy, 1Password, or any TOTP app, and scan
                this QR code:
            </p>

         <div style="display:flex; justify-content:center; margin: var(--space-4) 0;">
    <div id="qrcode"
         style="padding: 12px; background: #fff; border: 1px solid var(--border); border-radius: var(--radius-md); min-width: 220px; min-height: 220px; display: flex; align-items: center; justify-content: center;">
        <span style="color: var(--muted); font-size: 0.85rem;">Loading QR…</span>
    </div>
</div>

<script src="assets/js/qrcode.min.js"></script>
<script>
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var el = document.getElementById('qrcode');
        if (!el || typeof QRCode === 'undefined') {
            if (el) el.innerHTML = '<span style="color:var(--muted);">Enter the code manually below.</span>';
            return;
        }
        el.innerHTML = '';
        new QRCode(el, {
            text: <?= json_encode($uri, JSON_UNESCAPED_SLASHES) ?>,
            width: 220,
            height: 220,
            colorDark: '#1A1A1A',
            colorLight: '#FFFFFF',
            correctLevel: QRCode.CorrectLevel.M
        });
    });
})();
</script>

            <p style="color: var(--muted); font-size: 0.9rem;">
                Can't scan? Enter this code manually in your app:
            </p>
            <div style="font-family: 'SF Mono', Monaco, Consolas, monospace; padding: var(--space-3); background: var(--sand); border-radius: var(--radius-md); word-break: break-all; font-size: 0.95rem;">
                <?= htmlspecialchars($pendingSecret) ?>
            </div>

            <form method="POST" class="stack" style="margin-top: var(--space-5);">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="confirm_setup">

                <div class="form-field">
                    <label for="code">Enter the 6-digit code from your app</label>
                    <input type="text"
                           id="code"
                           name="code"
                           inputmode="numeric"
                           pattern="[0-9]{6}"
                           maxlength="6"
                           autocomplete="one-time-code"
                           required
                           placeholder="000000">
                </div>

                <div style="display:flex; gap: var(--space-2);">
                    <button type="submit">Confirm and enable 2FA</button>
                    <a href="settings_2fa.php" class="btn-secondary">Cancel</a>
                </div>
            </form>

            <p style="color: var(--muted); font-size: 0.8rem; margin-top: var(--space-3);">
                If your app shows a code and it says "invalid", make sure your phone's
                clock is set to update automatically. TOTP depends on accurate time.
            </p>
        </div>
    <?php endif; ?>

    <?php /* ============ OVERVIEW ============ */ ?>
    <?php if ($step === 'overview'): ?>
        <?php if (!(int)$user['totp_enabled']): ?>
            <div class="card">
                <h2 style="margin-top:0;">Status: Off</h2>
                <p>
                    2FA adds a second step to sign-in: after your password, you'll enter
                    a 6-digit code from an authenticator app on your phone.
                    Even if someone steals your password, they can't get in without your phone.
                </p>
                <p style="color: var(--muted); font-size: 0.9rem;">
                    Qarota doesn't send codes by SMS — SIM swapping is a real risk in many
                    African countries. We use TOTP instead, which works entirely offline.
                </p>

                <form method="POST" style="margin-top: var(--space-4);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="start_setup">
                    <button type="submit">
                        <i data-lucide="shield-check" style="width:16px;height:16px;vertical-align:middle;"></i>
                        Set up 2FA
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="card" style="border-left: 4px solid var(--baobab-green);">
                <h2 style="margin-top:0;">Status: On</h2>
                <p>
                    Your account is protected with 2FA. When you sign in, you'll be asked
                    for a 6-digit code in addition to your password.
                </p>
                <?php if (!empty($user['totp_confirmed_at'])): ?>
                    <p style="color: var(--muted); font-size: 0.85rem;">
                        Enabled <?= htmlspecialchars($user['totp_confirmed_at']) ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2 style="margin-top:0;">Backup codes</h2>
                <?php
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) AS total,
                           SUM(used_at IS NULL) AS remaining
                    FROM totp_backup_codes
                    WHERE user_id = :id
                ");
                $stmt->execute([':id' => $user_id]);
                $counts = $stmt->fetch(PDO::FETCH_ASSOC);
                ?>
                <p>
                    You have <strong><?= (int)$counts['remaining'] ?></strong> of <?= (int)$counts['total'] ?> backup codes remaining.
                    <?php if ((int)$counts['remaining'] <= 2): ?>
                        <br><span style="color: var(--danger);">You're running low — generate new ones now.</span>
                    <?php endif; ?>
                </p>

                <form method="POST" class="stack" style="margin-top: var(--space-3);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="regenerate_codes">
                    <div class="form-field">
                        <label for="regen_password">Password</label>
                        <input type="password" id="regen_password" name="password" required autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn-secondary">Generate new backup codes</button>
                </form>
            </div>

            <div class="card">
                <h2 style="margin-top:0;">Disable 2FA</h2>
                <p style="color: var(--muted); font-size: 0.9rem;">
                    We don't recommend this. Your account becomes much easier to compromise
                    without 2FA.
                </p>
                <form method="POST" class="stack" style="margin-top: var(--space-3);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="disable">

                    <div class="form-field">
                        <label for="disable_password">Password</label>
                        <input type="password" id="disable_password" name="password" required autocomplete="current-password">
                    </div>

                    <div class="form-field">
                        <label for="disable_code">Current 6-digit code from your app</label>
                        <input type="text"
                               id="disable_code"
                               name="code"
                               inputmode="numeric"
                               pattern="[0-9]{6}"
                               maxlength="6"
                               autocomplete="one-time-code"
                               required
                               placeholder="000000">
                    </div>

                    <button type="submit" class="btn-danger"
                            onclick="return confirm('Disable 2FA? Your account will be less secure.');">
                        Disable 2FA
                    </button>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <p style="text-align:center; font-size: 0.85rem; color: var(--muted); margin-top: var(--space-5);">
        <a href="change_password.php">Change your password →</a>
    </p>
</div>

<?php require 'includes/footer.php'; ?>