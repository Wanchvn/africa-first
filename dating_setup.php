<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require_once __DIR__ . '/includes/dating.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$me = (int)$_SESSION['user_id'];

$profile = get_dating_profile($pdo, $me);

$flash_error   = $_SESSION['flash_error']   ?? '';
$flash_success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

// Phase 3 data
$myPhotos     = $profile ? list_dating_photos($pdo, $me) : [];
$verification = $profile ? get_dating_verification($pdo, $me) : null;
$isVerified   = $verification && $verification['status'] === 'approved';

$page_title = 'Dating profile';
require 'includes/header.php';
?>

<div class="dating-container">
    <div class="dating-header">
        <h1>Dating profile</h1>
        <p class="dating-subtitle">
            Fill this out to be discoverable. You can turn it off anytime —
            your profile will be hidden from everyone when inactive.
        </p>
    </div>

    <div class="dating-mode-tabs">
        <a href="dating.php">Swipe</a>
        <a href="dating_browse.php">Browse</a>
        <a href="dating_matches.php">Matches</a>
    </div>

    <?php if ($flash_error): ?>
        <div class="message" style="border-left-color: var(--danger); background: #FDECEA;">
            <?= htmlspecialchars($flash_error) ?>
        </div>
    <?php endif; ?>

    <?php if ($flash_success): ?>
        <div class="message" style="border-left-color: var(--baobab-green); background: #E8F5E9;">
            <?= htmlspecialchars($flash_success) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" action="dating_save.php" class="stack">
            <?= csrf_field() ?>

            <div class="form-field">
                <label class="visibility-option" style="font-weight:600;">
                    <input type="checkbox" name="is_active" value="1"
                           <?= ($profile && (int)$profile['is_active'] === 1) ? 'checked' : '' ?>>
                    <span>Show my dating profile</span>
                </label>
                <div class="field-hint">
                    When off, you won't appear in anyone's browse results and you can't browse others.
                </div>
            </div>

            <div class="dating-grid-2">
                <div class="form-field">
                    <label for="age">Age</label>
                    <input type="number" id="age" name="age" min="18" max="99"
                           value="<?= htmlspecialchars((string)($profile['age'] ?? '')) ?>">
                    <div class="field-hint">18+ only. This is enforced when you activate.</div>
                </div>

                <div class="form-field">
                    <label for="location">Location</label>
                    <input type="text" id="location" name="location" maxlength="120"
                           placeholder="e.g. Tamale, Ghana"
                           value="<?= htmlspecialchars((string)($profile['location'] ?? '')) ?>">
                </div>

                <div class="form-field">
                    <label for="gender">I am a…</label>
                    <select id="gender" name="gender">
                        <option value="">— Choose —</option>
                        <?php foreach (['man' => 'Man', 'woman' => 'Woman', 'nonbinary' => 'Non-binary', 'other' => 'Other'] as $v => $lbl): ?>
                            <option value="<?= $v ?>" <?= ($profile['gender'] ?? '') === $v ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field">
                    <label for="interested_in">Interested in</label>
                    <select id="interested_in" name="interested_in">
                        <option value="">— Choose —</option>
                        <?php foreach (['men' => 'Men', 'women' => 'Women', 'everyone' => 'Everyone'] as $v => $lbl): ?>
                            <option value="<?= $v ?>" <?= ($profile['interested_in'] ?? '') === $v ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field">
                    <label for="looking_for">Looking for</label>
                    <select id="looking_for" name="looking_for">
                        <option value="">— Choose —</option>
                        <?php
                        $lf = ['relationship' => 'A relationship', 'marriage' => 'Marriage', 'friendship' => 'Friendship', 'unsure' => 'Not sure yet'];
                        foreach ($lf as $v => $lbl):
                        ?>
                            <option value="<?= $v ?>" <?= ($profile['looking_for'] ?? '') === $v ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field">
                    <label for="occupation">Occupation <span class="optional">(optional)</span></label>
                    <input type="text" id="occupation" name="occupation" maxlength="120"
                           value="<?= htmlspecialchars((string)($profile['occupation'] ?? '')) ?>">
                </div>

                <div class="form-field">
                    <label for="education">Education <span class="optional">(optional)</span></label>
                    <input type="text" id="education" name="education" maxlength="120"
                           value="<?= htmlspecialchars((string)($profile['education'] ?? '')) ?>">
                </div>

                <div class="form-field">
                    <label for="interests">Interests <span class="optional">(optional)</span></label>
                    <input type="text" id="interests" name="interests" maxlength="500"
                           placeholder="e.g. music, cooking, football"
                           value="<?= htmlspecialchars((string)($profile['interests'] ?? '')) ?>">
                    <div class="field-hint">Comma-separated. Max 500 characters.</div>
                </div>
            </div>

            <div class="form-field">
                <label for="bio">About you</label>
                <textarea id="bio" name="bio" rows="6" maxlength="2000"
                          placeholder="Say a little about yourself…"><?= htmlspecialchars((string)($profile['bio'] ?? '')) ?></textarea>
                <div class="field-hint">Max 2000 characters.</div>
            </div>

            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <button type="submit">Save profile</button>
                <a href="dating.php" class="btn-secondary">Browse profiles</a>
            </div>
        </form>
    </div>

    <!-- PHASE 3: Photos -->
    <div class="card" id="photos">
        <h2 style="margin-top:0;">Photos</h2>
        <p style="color:var(--muted); font-size:0.9rem;">
            Up to <?= DATING_PHOTO_LIMIT ?> photos. The first one is shown on your swipe card.
        </p>

        <?php if (!$profile || (int)$profile['is_active'] !== 1): ?>
            <p style="color:var(--muted); font-size:0.9rem;">
                Activate your dating profile above to upload photos.
            </p>
        <?php else: ?>

            <?php if (!empty($myPhotos)): ?>
                <div class="dating-photo-grid">
                    <?php foreach ($myPhotos as $p): ?>
                        <div class="dating-photo-tile">
                            <img src="dating_photo_serve.php?id=<?= (int)$p['id'] ?>" alt="">
                            <form method="POST" action="dating_photo_delete.php"
                                  onsubmit="return confirm('Delete this photo?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="photo_id" value="<?= (int)$p['id'] ?>">
                                <button type="submit" class="dating-photo-delete" title="Delete">✕</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (count($myPhotos) < DATING_PHOTO_LIMIT): ?>
                <form method="POST" action="dating_photos_upload.php" enctype="multipart/form-data"
                      class="stack" style="margin-top: var(--space-3);">
                    <?= csrf_field() ?>
                    <div class="form-field">
                        <label for="photo">Add a photo</label>
                        <input type="file" id="photo" name="photo"
                               accept="image/jpeg,image/png,image/webp" required>
                        <div class="field-hint">JPG, PNG, or WebP. Max 8 MB.</div>
                    </div>
                    <button type="submit">Upload</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- PHASE 3: Verification -->
    <div class="card">
        <h2 style="margin-top:0;">Verification</h2>

        <?php if (!$profile || (int)$profile['is_active'] !== 1): ?>
            <p style="color:var(--muted); font-size:0.9rem;">
                Activate your dating profile above to get verified.
            </p>
        <?php elseif ($isVerified): ?>
            <p style="color:var(--baobab-green); font-weight:600;">✓ Verified</p>
            <p style="color:var(--muted); font-size:0.9rem;">
                Your dating profile shows a verified badge.
                Verified <?= htmlspecialchars(substr($verification['resolved_at'] ?? '', 0, 10)) ?>.
            </p>
        <?php elseif ($verification && $verification['status'] === 'pending'): ?>
            <p style="color:var(--clay); font-weight:600;">⏳ Pending review</p>
            <p style="color:var(--muted); font-size:0.9rem;">
                An admin will review your selfie within 24 hours.
                Submitted <?= htmlspecialchars($verification['submitted_at']) ?>.
            </p>
        <?php elseif ($verification && $verification['status'] === 'rejected'): ?>
            <p style="color:var(--danger); font-weight:600;">Verification was not approved.</p>
            <?php if (!empty($verification['admin_note'])): ?>
                <p style="color:var(--muted); font-size:0.9rem;">
                    Reason: <?= htmlspecialchars($verification['admin_note']) ?>
                </p>
            <?php endif; ?>
            <a href="dating_verify.php" class="btn-secondary">Try again</a>
        <?php else: ?>
            <p style="color:var(--muted); font-size:0.9rem;">
                Verified profiles get more replies. It takes about a minute.
            </p>
            <a href="dating_verify.php" class="btn">Verify my profile</a>
        <?php endif; ?>
    </div>
</div>

<?php require 'includes/footer.php'; ?>