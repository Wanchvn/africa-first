<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/topics.php';
require 'includes/rate_limit.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$message = '';
$errors = [];
$name = '';
$description = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    rate_limit_enforce($pdo, client_ip(), 'create_topic', 5, 3600);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    // Validate name
    if ($name === '') {
        $errors[] = 'Topic name is required.';
    } elseif (mb_strlen($name) < 3) {
        $errors[] = 'Topic name must be at least 3 characters.';
    } elseif (mb_strlen($name) > 50) {
        $errors[] = 'Topic name must be 50 characters or less.';
    } elseif (!preg_match('/^[a-zA-Z0-9 _-]+$/', $name)) {
        $errors[] = 'Topic name can contain letters, numbers, spaces, hyphens, and underscores.';
    }

    if (mb_strlen($description) > 280) {
        $errors[] = 'Description must be 280 characters or less.';
    }

    if (empty($errors)) {
        $slug = topic_slug($name);

        // Check if slug already exists
        $stmt = $pdo->prepare("SELECT id FROM topics WHERE slug = :slug");
        $stmt->execute([':slug' => $slug]);

        if ($stmt->fetch()) {
            $errors[] = 'A topic with this name already exists.';
        } else {
            // Insert the topic
            $stmt = $pdo->prepare("
                INSERT INTO topics (name, slug, description, curator_id)
                VALUES (:name, :slug, :description, :curator)
            ");
            $stmt->execute([
                ':name' => $name,
                ':slug' => $slug,
                ':description' => $description !== '' ? $description : null,
                ':curator' => $user_id,
            ]);
            $topic_id = (int)$pdo->lastInsertId();

            // Auto-join the creator
            $stmt = $pdo->prepare("INSERT INTO topic_members (topic_id, user_id) VALUES (:t, :u)");
            $stmt->execute([':t' => $topic_id, ':u' => $user_id]);

            // Redirect to the new topic
            header('Location: topic.php?slug=' . urlencode($slug));
            exit;
        }
    }
}

$page_title = 'Create a topic';
require 'includes/header.php';
?>

<h1>Create a topic</h1>

<p style="color: var(--muted); margin-bottom: var(--space-5);">
    Topics are community-owned spaces. You'll be the curator — you set the description and can remove off-topic posts.
</p>

<?php if (!empty($errors)): ?>
    <div class="message" style="border-left-color: #c0392b; background: #FDECEA;">
        <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card">
    <form method="POST" class="stack">
        <?= csrf_field() ?>

        <div class="form-field">
            <label for="name">Topic name</label>
            <input type="text"
                   id="name"
                   name="name"
                   value="<?= htmlspecialchars($name) ?>"
                   maxlength="50"
                   placeholder="e.g. TamaleFood, GhanaTech, DagbaniMusic"
                   required>
            <div class="field-hint">
                Keep it short and specific. Letters, numbers, spaces, hyphens, underscores.
            </div>
        </div>

        <div class="form-field">
            <label for="description">Description <span class="optional">(optional)</span></label>
            <textarea id="description"
                      name="description"
                      maxlength="280"
                      placeholder="What is this topic about? One or two sentences."
                      oninput="document.getElementById('descCount').textContent = 280 - this.value.length"><?= htmlspecialchars($description) ?></textarea>
            <div class="field-hint" style="text-align:right;">
                <span id="descCount"><?= 280 - mb_strlen($description) ?></span> characters left
            </div>
        </div>

        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button type="submit">Create topic</button>
            <a href="topics.php" class="btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<?php require 'includes/footer.php'; ?>