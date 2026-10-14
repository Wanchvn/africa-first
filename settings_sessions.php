<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require_once __DIR__ . '/includes/sessions_tracker.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$current_sid = session_id();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    rate_limit_enforce($pdo, client_ip(), 'sessions_action', 30, 3600);

    $action = $_POST['action'] ?? '';

    if ($action === 'revoke_one') {
        $target_sid = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['session_id'] ?? '');
        if ($target_sid === $current_sid) {
            $error = 'You cannot revoke your current session from this page. Use the logout button instead.';
        } elseif (revoke_session($pdo, $user_id, $target_sid)) {
            $success = 'Session signed out.';
        } else {
            $error = 'That session was already ended or does not exist.';
        }
    }

    elseif ($action === 'revoke_all_others') {
        $count = revoke_other_sessions($pdo, $user_id);
        $success = $count === 0
            ? 'No other sessions were active.'
            : 'Signed out of ' . $count . ' other ' . ($count === 1 ? 'session' : 'sessions') . '.';
    }
}

// Load this user's sessions (recent first, non-ancient)
$stmt = $pdo->prepare("
    SELECT id, ip_address, user_agent, device_label, last_seen_at, created_at, revoked_at
    FROM user_sessions
    WHERE user_id = :uid
      AND last_seen_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY last_seen_at DESC
");
$stmt->execute([':uid' => $user_id]);
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Active sessions';
require 'includes/header.php';
?>

<div class="container" style="max-width: 720px;">
    <h1>Active sessions</h1>

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

    <div class="card">
        <h2 style="margin-top:0;">Where you're signed in</h2>
        <p style="color: var(--muted); font-size: 0.9rem;">
            Every device with an active session on your account. If you see something
            you don't recognize, sign it out — and change your password immediately.
        </p>

        <?php if (empty($sessions)): ?>
            <div class="empty">No sessions found.</div>
        <?php else: ?>
            <div class="sessions-list">
                <?php foreach ($sessions as $s): ?>
                    <?php
                    $is_current  = ($s['id'] === $current_sid);
                    $is_revoked  = !empty($s['revoked_at']);
                    $last_seen   = $s['last_seen_at'];
                    $last_seen_ts = strtotime($last_seen);
                    $mins_ago    = max(0, (int)floor((time() - $last_seen_ts) / 60));

                    if ($mins_ago < 1)       $seen_label = 'Active now';
                    elseif ($mins_ago < 60)  $seen_label = $mins_ago . ' min ago';
                    elseif ($mins_ago < 1440) $seen_label = floor($mins_ago / 60) . ' hr ago';
                    else                     $seen_label = floor($mins_ago / 1440) . ' days ago';
                    ?>
                    <div class="session-row <?= $is_current ? 'session-current' : '' ?> <?= $is_revoked ? 'session-revoked' : '' ?>">
                        <div class="session-icon">
                            <?php if (stripos($s['device_label'] ?? '', 'mobile') !== false): ?>
                                <i data-lucide="smartphone"></i>
                            <?php elseif (stripos($s['device_label'] ?? '', 'tablet') !== false): ?>
                                <i data-lucide="tablet"></i>
                            <?php else: ?>
                                <i data-lucide="monitor"></i>
                            <?php endif; ?>
                        </div>

                        <div class="session-info">
                            <div class="session-device">
                                <?= htmlspecialchars($s['device_label'] ?: 'Unknown device') ?>
                                <?php if ($is_current): ?>
                                    <span class="session-badge">Current</span>
                                <?php elseif ($is_revoked): ?>
                                    <span class="session-badge session-badge-revoked">Revoked</span>
                                <?php endif; ?>
                            </div>
                            <div class="session-meta">
                                <span><?= htmlspecialchars($s['ip_address'] ?: 'IP unknown') ?></span>
                                <span class="session-meta-sep">·</span>
                                <span title="<?= htmlspecialchars($last_seen) ?>"><?= htmlspecialchars($seen_label) ?></span>
                            </div>
                        </div>

                        <div class="session-actions">
                            <?php if (!$is_current && !$is_revoked): ?>
                                <form method="POST" style="margin:0;"
                                      onsubmit="return confirm('Sign out this session?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="revoke_one">
                                    <input type="hidden" name="session_id" value="<?= htmlspecialchars($s['id']) ?>">
                                    <button type="submit" class="btn-secondary btn-small">Sign out</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (count($sessions) > 1): ?>
        <div class="card">
            <h2 style="margin-top:0;">Sign out everywhere else</h2>
            <p style="color: var(--muted); font-size: 0.9rem;">
                This will end every session except the one you're using right now.
                Useful after a password change or if you're not sure which devices
                should still be signed in.
            </p>
            <form method="POST"
                  onsubmit="return confirm('Sign out all other sessions? You will stay signed in on this device.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="revoke_all_others">
                <button type="submit" class="btn-danger">Sign out other sessions</button>
            </form>
        </div>
    <?php endif; ?>

    <p style="text-align:center; font-size: 0.85rem; color: var(--muted); margin-top: var(--space-5);">
        <a href="settings_2fa.php">Two-factor authentication →</a>
    </p>
</div>

<?php require 'includes/footer.php'; ?>