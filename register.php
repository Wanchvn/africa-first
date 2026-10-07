<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require 'includes/username.php';

if (isset($_SESSION['user_id'])) {
    header('Location: profile.php');
    exit;
}

$message = '';
$entered_username = '';
$entered_email = '';
$terms_checked = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    rate_limit_enforce($pdo, client_ip(), 'register', 3, 3600);

    $entered_username = trim($_POST['username'] ?? '');
    $entered_email    = trim($_POST['email'] ?? '');
    $password         = $_POST['password'] ?? '';
    $terms_checked    = !empty($_POST['terms']);

    if (empty($entered_username) || empty($entered_email) || empty($password)) {
        $message = __('register_error_required');
    } elseif (!filter_var($entered_email, FILTER_VALIDATE_EMAIL)) {
        $message = __('register_error_email');
    } elseif (mb_strlen($password) < 8) {
        $message = __('register_error_password');
    } elseif (!$terms_checked) {
        $message = __('register_error_terms');
    } elseif (($err = validate_username($entered_username)) !== null) {
        $message = $err;
    } elseif (!is_username_available($pdo, $entered_username)) {
        $alternative = suggest_username($pdo, $entered_email);
        $message = "That username is taken. Try \"$alternative\" instead.";
        $entered_username = $alternative;
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute([':email' => $entered_email]);

        if ($stmt->fetch()) {
            $message = __('register_error_taken');
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO users (username, email, password, terms_accepted_at)
                VALUES (:username, :email, :password, NOW())
            ");
            $stmt->execute([
                ':username' => $entered_username,
                ':email'    => $entered_email,
                ':password' => $hash,
            ]);

            // Auto-login after successful registration
            $new_user_id = (int)$pdo->lastInsertId();
            $_SESSION['user_id'] = $new_user_id;
            $_SESSION['username'] = $entered_username;
            regenerate_session_on_login();

            header('Location: welcome.php');
            exit;
        }
    }
}

$page_title = __('register_title');
require 'includes/header.php';
?>

<div class="welcome-hero">
    <h1 class="welcome-title">Join Qarota</h1>
    <p class="welcome-subtitle">
        Create your account in 30 seconds. No phone number required,
        no ads, no algorithm — just you and the community.
    </p>

    <div class="welcome-pillars">
        <div class="welcome-pillar">
            <div class="welcome-icon">⚡</div>
            <div class="welcome-label">Fast signup</div>
        </div>
        <div class="welcome-pillar">
            <div class="welcome-icon">🔒</div>
            <div class="welcome-label">Private by default</div>
        </div>
        <div class="welcome-pillar">
            <div class="welcome-icon">🌍</div>
            <div class="welcome-label">Made for Africa</div>
        </div>
    </div>
</div>

<?php if ($message): ?>
    <div class="message"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="card">
    <h2 style="margin-top:0;"><?= __('register_title') ?></h2>
    <form method="POST" class="stack" id="registerForm" novalidate>
        <?= csrf_field() ?>

        <div class="form-field">
            <label for="email"><?= __('register_email_label') ?></label>
            <input type="email"
                   id="email"
                   name="email"
                   value="<?= htmlspecialchars($entered_email) ?>"
                   placeholder="you@example.com"
                   autocomplete="email"
                   required>
            <div class="field-hint"><?= __('register_email_hint') ?></div>
        </div>

        <div class="form-field">
            <label for="username"><?= __('register_username_label') ?></label>
            <div class="username-row">
                <input type="text"
                       id="username"
                       name="username"
                       value="<?= htmlspecialchars($entered_username) ?>"
                       placeholder="your.username"
                       autocomplete="username"
                       maxlength="30"
                       required>
                <button type="button" id="suggestBtn" class="btn-secondary btn-small">
                    Suggest
                </button>
            </div>
            <div class="field-hint" id="usernameHint">
                <?= __('register_username_hint') ?>
            </div>
        </div>

        <div class="form-field">
            <label for="password"><?= __('register_password_label') ?></label>
            <div class="password-row">
                <input type="password"
                       id="password"
                       name="password"
                       placeholder="<?= __('register_password_placeholder') ?>"
                       autocomplete="new-password"
                       minlength="8"
                       required>
                <button type="button" id="togglePassword" class="password-toggle" aria-label="Show password">
                    <span id="toggleIcon">👁</span>
                </button>
            </div>
            <div class="password-strength" id="strengthBar">
                <div class="strength-track">
                    <div class="strength-fill" id="strengthFill"></div>
                </div>
                <div class="strength-label" id="strengthLabel"></div>
            </div>
        </div>

        <div class="form-field">
            <label class="terms-checkbox">
                <input type="checkbox"
                       name="terms"
                       id="terms"
                       value="1"
                       <?= $terms_checked ? 'checked' : '' ?>
                       required>
                <span>
                    <?= __('register_terms_label') ?>
                    (<a href="terms.php" target="_blank"><?= __('register_terms_link_terms') ?></a>,
                    <a href="privacy_policy.php" target="_blank"><?= __('register_terms_link_privacy') ?></a>)
                </span>
            </label>
        </div>

        <button type="submit" id="submitBtn"><?= __('register_button') ?></button>
    </form>
</div>

<p style="text-align:center;">
    <?= __('register_have_account') ?>
    <a href="login.php"><strong><?= __('register_login_link') ?></strong></a>
</p>


<script>
window.__registerLabels = {
    checking: <?= json_encode(__('register_username_checking')) ?>,
    available: <?= json_encode(__('register_username_available')) ?>,
    taken: <?= json_encode(__('register_username_taken')) ?>,
    hint: <?= json_encode(__('register_username_hint')) ?>,
    weak: <?= json_encode(__('register_strength_weak')) ?>,
    fair: <?= json_encode(__('register_strength_fair')) ?>,
    good: <?= json_encode(__('register_strength_good')) ?>,
    strong: <?= json_encode(__('register_strength_strong')) ?>,
};
</script>

<?php require 'includes/footer.php'; ?>