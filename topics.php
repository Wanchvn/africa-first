<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/topics.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Fetch all topics, ordered by member count
$stmt = $pdo->prepare("
    SELECT
        t.id, t.name, t.slug, t.description, t.curator_id,
        u.username AS curator_username, u.display_name AS curator_display_name,
        (SELECT COUNT(*) FROM topic_members WHERE topic_id = t.id) AS member_count,
        (SELECT COUNT(*) FROM post_topics WHERE topic_id = t.id) AS post_count,
        (SELECT 1 FROM topic_members WHERE topic_id = t.id AND user_id = :me) AS joined
    FROM topics t
    INNER JOIN users u ON t.curator_id = u.id
    ORDER BY member_count DESC, t.created_at DESC
");
$stmt->execute([':me' => $user_id]);
$topics = $stmt->fetchAll();

$page_title = 'Topics';
require 'includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: var(--space-4);">
    <h1 style="margin:0;">Topics</h1>
    <a href="create_topic.php" class="btn">+ New topic</a>
</div>

<p style="color: var(--muted); margin-bottom: var(--space-5);">
    Community-owned spaces for shared interests. Every topic has a curator.
</p>

<?php if (empty($topics)): ?>
    <div class="empty">
        <strong>No topics yet</strong>
        Be the first to create one. Pick something you care about and give it a home.
        <div style="margin-top: var(--space-4);">
            <a href="create_topic.php" class="btn">Create the first topic</a>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($topics as $t): ?>
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap: var(--space-3); flex-wrap:wrap;">
                <div style="flex:1; min-width: 200px;">
                    <h2 style="margin: 0 0 var(--space-1);">
                        <a href="topic.php?slug=<?= urlencode($t['slug']) ?>" style="color: var(--deep-brown); text-decoration: none;">
                            #<?= htmlspecialchars($t['name']) ?>
                        </a>
                    </h2>
                    <?php if ($t['description']): ?>
                        <p style="color: var(--muted); margin: var(--space-2) 0; font-size: 0.95rem;">
                            <?= htmlspecialchars($t['description']) ?>
                        </p>
                    <?php endif; ?>
                    <div class="profile-stats" style="margin: var(--space-2) 0 0;">
                        <strong><?= (int)$t['member_count'] ?></strong> <?= $t['member_count'] == 1 ? 'member' : 'members' ?>
                        <span class="stat-dot">·</span>
                        <strong><?= (int)$t['post_count'] ?></strong> <?= $t['post_count'] == 1 ? 'post' : 'posts' ?>
                        <span class="stat-dot">·</span>
                        Curated by
                        <a href="profile.php?u=<?= urlencode($t['curator_username']) ?>">
                            <?= htmlspecialchars($t['curator_display_name'] ?: $t['curator_username']) ?>
                        </a>
                    </div>
                </div>

                <div>
                    <?php if ($t['joined']): ?>
                        <a href="topic.php?slug=<?= urlencode($t['slug']) ?>" class="btn-secondary btn-small">Open</a>
                    <?php else: ?>
                        <a href="topic.php?slug=<?= urlencode($t['slug']) ?>" class="btn-secondary btn-small">View</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="end-of-feed">
        <strong>That's all the topics</strong>
        Create a new one if you don't see what you're looking for.
    </div>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>