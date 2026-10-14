<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/rate_limit.php';
require_once __DIR__ . '/includes/verification.php';
require_once __DIR__ . '/includes/badge.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Load user's current verification state
$stmt = $pdo->prepare("SELECT verification_type FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $user_id]);
$current_type = (string)$stmt->fetchColumn();

if ($current_type !== 'none' && $current_type !== '') {
    $_SESSION['flash_success'] = 'You are already verified.';
    header('Location: profile.php');
    exit;
}

$existing = verification_application_status($pdo, $user_id);
if ($existing && $existing['status'] === 'pending') {
    header('Location: verify_apply_status.php');
    exit;
}

$errors  = [];
$old     = [
    'category'        => '',
    'full_legal_name' => '',
    'country'         => '',
    'id_type'         => '',
    'id_number'       => '',
    'reason'          => '',
    'public_url'      => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    rate_limit_enforce($pdo, client_ip(), 'verify_apply', 3, 86400);

    foreach ($old as $k => $_) {
        $old[$k] = trim((string)($_POST[$k] ?? ''));
    }

    $allowed_categories = ['journalist','public_figure','organization','business','other'];
    $allowed_id_types   = ['national_id','passport','drivers_license'];

    if (!in_array($old['category'], $allowed_categories, true))    $errors[] = 'Please choose a category.';
    if (mb_strlen($old['full_legal_name']) < 3
        || mb_strlen($old['full_legal_name']) > 150)               $errors[] = 'Full legal name is required (3–150 characters).';
    if ($old['country'] === '')                                     $errors[] = 'Country is required.';
    if (!in_array($old['id_type'], $allowed_id_types, true))       $errors[] = 'Please choose an ID type.';
    if (mb_strlen($old['id_number']) < 3
        || mb_strlen($old['id_number']) > 80)                      $errors[] = 'ID number is required (3–80 characters).';
    if (mb_strlen($old['reason']) < 50
        || mb_strlen($old['reason']) > 2000)                       $errors[] = 'Please write between 50 and 2000 characters explaining your request.';
    if ($old['public_url'] !== '' && !filter_var($old['public_url'], FILTER_VALIDATE_URL)) {
        $errors[] = 'Public URL must be a valid link.';
    }

    $idFront = $_FILES['id_front'] ?? null;
    $idBack  = $_FILES['id_back']  ?? null;
    $selfie  = $_FILES['selfie']   ?? null;

    $hasFront  = $idFront && ($idFront['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    $hasBack   = $idBack  && ($idBack['error']  ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    $hasSelfie = $selfie  && ($selfie['error']  ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

    if (!$hasFront)  $errors[] = 'Front of ID is required.';
    if (!$hasSelfie) $errors[] = 'A selfie holding your ID is required.';

    $saved = [];

    if (empty($errors)) {
        try {
            $saved['id_front'] = handle_verification_upload($idFront, $user_id, 'front');
            if ($hasBack) {
                $saved['id_back'] = handle_verification_upload($idBack, $user_id, 'back');
            }
            $saved['selfie'] = handle_verification_upload($selfie, $user_id, 'selfie');
        } catch (Throwable $e) {
            // Clean up anything we already saved
            foreach ($saved as $rel) {
                $abs = __DIR__ . '/' . $rel;
                if (is_file($abs)) @unlink($abs);
            }
            $errors[] = 'Upload failed: ' . $e->getMessage();
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO verification_applications
                    (user_id, status, category, full_legal_name, country, id_type, id_number,
                     reason, public_url, id_front_path, id_back_path, selfie_path, created_at)
                VALUES
                    (:uid, 'pending', :cat, :name, :country, :idtype, :idnum,
                     :reason, :url, :front, :back, :selfie, NOW())
            ");
            $stmt->execute([
                ':uid'     => $user_id,
                ':cat'     => $old['category'],
                ':name'    => $old['full_legal_name'],
                ':country' => $old['country'],
                ':idtype'  => $old['id_type'],
                ':idnum'   => $old['id_number'],
                ':reason'  => $old['reason'],
                ':url'     => $old['public_url'] !== '' ? $old['public_url'] : null,
                ':front'   => $saved['id_front'],
                ':back'    => $saved['id_back']  ?? null,
                ':selfie'  => $saved['selfie'],
            ]);

            $_SESSION['flash_success'] = 'Your verification request has been submitted.';
            header('Location: verify_apply_status.php');
            exit;
        } catch (Throwable $e) {
            foreach ($saved as $rel) {
                $abs = __DIR__ . '/' . $rel;
                if (is_file($abs)) @unlink($abs);
            }
            $errors[] = 'Could not save your application. Please try again.';
            error_log('verify_apply insert error: ' . $e->getMessage());
        }
    }
}

$flash_error   = $_SESSION['flash_error']   ?? '';
$flash_success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

$page_title = 'Get verified';
require 'includes/header.php';
?>

<div class="container" style="max-width: 720px;">
    <h1>Get verified</h1>

    <div class="card" style="border-left: 4px solid var(--terracotta);">
        <h2 style="margin-top:0;">What verification means</h2>
        <p>
            A verification badge tells people your identity has been confirmed by Qarota.
            It's available to journalists, public figures, organizations, and businesses.
            We do not sell verification.
        </p>
        <p style="color: var(--muted); font-size: 0.9rem; margin-bottom:0;">
            Your ID is only seen by Qarota admins. Photos are stored outside the public folder,
            and they are <strong>permanently deleted 30 days after your application is resolved</strong>.
            We never share your ID with anyone.
        </p>
    </div>

    <?php if ($flash_error): ?>
        <div class="message" style="border-left-color: var(--danger); background: #FDECEA;">
            <?= htmlspecialchars($flash_error) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="message" style="border-left-color: var(--danger); background: #FDECEA;">
            <?php foreach ($errors as $err): ?>
                <div><?= htmlspecialchars($err) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="card stack">
        <?= csrf_field() ?>

        <div class="form-field">
            <label for="category">Category</label>
            <select id="category" name="category" required>
                <option value="">— Choose —</option>
                <option value="journalist"     <?= $old['category'] === 'journalist'     ? 'selected' : '' ?>>Journalist</option>
                <option value="public_figure"  <?= $old['category'] === 'public_figure'  ? 'selected' : '' ?>>Public figure</option>
                <option value="organization"   <?= $old['category'] === 'organization'   ? 'selected' : '' ?>>Organization / NGO</option>
                <option value="business"       <?= $old['category'] === 'business'       ? 'selected' : '' ?>>Business</option>
                <option value="other"          <?= $old['category'] === 'other'          ? 'selected' : '' ?>>Other</option>
            </select>
        </div>

        <div class="form-field">
            <label for="full_legal_name">Full legal name (as it appears on your ID)</label>
            <input type="text" id="full_legal_name" name="full_legal_name" maxlength="150" required
                   value="<?= htmlspecialchars($old['full_legal_name']) ?>">
        </div>

        <div class="form-field">
            <label for="country">Country</label>
            <select id="country" name="country" required>
                <option value="">— Choose —</option>
                <?php
                $countries = [
                    'Ghana','Nigeria','Kenya','South Africa','Côte d\'Ivoire','Togo','Benin',
                    'Senegal','Cameroon','Egypt','Morocco','Ethiopia','Tanzania','Uganda',
                    'Rwanda','Mali','Burkina Faso','Niger','Chad','Sudan','Algeria','Tunisia',
                    'Libya','Somalia','Zambia','Zimbabwe','Botswana','Namibia','Mozambique',
                    'Angola','Madagascar','Mauritius','Gabon','Congo','DR Congo','Liberia',
                    'Sierra Leone','Gambia','Guinea','United States','United Kingdom','Canada',
                    'France','Germany','India','China','Brazil','Other'
                ];
                foreach ($countries as $c):
                    $sel = $old['country'] === $c ? 'selected' : '';
                ?>
                    <option value="<?= htmlspecialchars($c) ?>" <?= $sel ?>><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-field">
            <label for="id_type">ID type</label>
            <select id="id_type" name="id_type" required>
                <option value="">— Choose —</option>
                <option value="national_id"     <?= $old['id_type'] === 'national_id'     ? 'selected' : '' ?>>National ID card</option>
                <option value="passport"        <?= $old['id_type'] === 'passport'        ? 'selected' : '' ?>>Passport</option>
                <option value="drivers_license" <?= $old['id_type'] === 'drivers_license' ? 'selected' : '' ?>>Driver's license</option>
            </select>
        </div>

        <div class="form-field">
            <label for="id_number">ID number</label>
            <input type="text" id="id_number" name="id_number" maxlength="80" required
                   value="<?= htmlspecialchars($old['id_number']) ?>">
        </div>

        <div class="form-field">
            <label for="reason">Why should you be verified?</label>
            <textarea id="reason" name="reason" rows="6" minlength="50" maxlength="2000" required
                      placeholder="Describe your work, your audience, and why a badge helps people recognize you as the real person. 50–2000 characters."><?= htmlspecialchars($old['reason']) ?></textarea>
        </div>

        <div class="form-field">
            <label for="public_url">Public profile URL <span class="optional">(optional)</span></label>
            <input type="url" id="public_url" name="public_url" maxlength="255"
                   placeholder="https://..." value="<?= htmlspecialchars($old['public_url']) ?>">
        </div>

        <h3 style="margin-top: var(--space-5); margin-bottom: var(--space-2);">Upload documents</h3>
        <p style="color: var(--muted); font-size: 0.85rem; margin-top:0;">
            JPG, PNG, or WEBP. Max 5 MB per file.
        </p>

        <div class="form-field">
            <label for="id_front">Front of your ID</label>
            <input type="file" id="id_front" name="id_front" accept="image/jpeg,image/png,image/webp" required>
        </div>

        <div class="form-field">
            <label for="id_back">Back of your ID <span class="optional">(optional)</span></label>
            <input type="file" id="id_back" name="id_back" accept="image/jpeg,image/png,image/webp">
        </div>

        <div class="form-field">
            <label for="selfie">Selfie holding your ID</label>
            <input type="file" id="selfie" name="selfie" accept="image/jpeg,image/png,image/webp" required>
            <div class="field-hint">Hold your ID next to your face. Make sure both are clearly visible.</div>
        </div>

        <div style="display:flex; gap: var(--space-2); margin-top: var(--space-4);">
            <button type="submit">Submit for review</button>
            <a href="profile.php" class="btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<?php require 'includes/footer.php'; ?>