<?php
session_start();
require 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Fetch account information
$stmt = $pdo->prepare("
    SELECT id, username, email, avatar, created_at, is_admin
    FROM users
    WHERE id = :id
");
$stmt->execute([':id' => $user_id]);
$account = $stmt->fetch();

// Count activity
$stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE user_id = :id");
$stmt->execute([':id' => $user_id]);
$post_count = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE user_id = :id");
$stmt->execute([':id' => $user_id]);
$comment_count = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE user_id = :id");
$stmt->execute([':id' => $user_id]);
$like_count = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE follower_id = :id");
$stmt->execute([':id' => $user_id]);
$following_count = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE following_id = :id");
$stmt->execute([':id' => $user_id]);
$follower_count = (int)$stmt->fetchColumn();

// What the platform CAN see — fields we don't collect
$not_collected = [
    'Phone number',
    'Location data',
    'Contacts / address book',
    'Browsing history outside Qarota',
    'Device identifiers for advertising',
    'Biometric data',
    'Data for ad targeting',
];

$page_title = 'Your Privacy';
require 'includes/header.php';
?>

<h1>Your privacy</h1>

<p style="color: var(--muted); margin-bottom: 20px;">
    This is everything Qarota knows about you. All of it. Nothing hidden.
</p>

<div class="card">
    <h2 style="margin-top:0;">What we store about you</h2>
    <table style="width:100%; border-collapse: collapse;">
        <tr>
            <td style="padding:8px 0; color:var(--muted);">Username</td>
            <td style="padding:8px 0;"><?= htmlspecialchars($account['username']) ?></td>
        </tr>
        <tr>
            <td style="padding:8px 0; color:var(--muted);">Email</td>
            <td style="padding:8px 0;"><?= htmlspecialchars($account['email']) ?></td>
        </tr>
        <tr>
            <td style="padding:8px 0; color:var(--muted);">Avatar</td>
            <td style="padding:8px 0;"><?= $account['avatar'] ? 'Yes (1 file)' : 'None uploaded' ?></td>
        </tr>
        <tr>
            <td style="padding:8px 0; color:var(--muted);">Account created</td>
            <td style="padding:8px 0;"><?= htmlspecialchars($account['created_at']) ?></td>
        </tr>
        <tr>
            <td style="padding:8px 0; color:var(--muted);">Password</td>
            <td style="padding:8px 0;">Stored as a one-way hash. Even we cannot read it.</td>
        </tr>
    </table>
</div>

<div class="card">
    <h2 style="margin-top:0;">What you've shared</h2>
    <table style="width:100%; border-collapse: collapse;">
        <tr>
            <td style="padding:8px 0; color:var(--muted);">Posts</td>
            <td style="padding:8px 0;"><?= $post_count ?></td>
        </tr>
        <tr>
            <td style="padding:8px 0; color:var(--muted);">Comments</td>
            <td style="padding:8px 0;"><?= $comment_count ?></td>
        </tr>
        <tr>
            <td style="padding:8px 0; color:var(--muted);">Likes given</td>
            <td style="padding:8px 0;"><?= $like_count ?></td>
        </tr>
        <tr>
            <td style="padding:8px 0; color:var(--muted);">Following</td>
            <td style="padding:8px 0;"><?= $following_count ?></td>
        </tr>
        <tr>
            <td style="padding:8px 0; color:var(--muted);">Followers</td>
            <td style="padding:8px 0;"><?= $follower_count ?></td>
        </tr>
    </table>
</div>

<div class="card" style="background: #FFF9F5; border-left: 4px solid var(--baobab-green);">
    <h2 style="margin-top:0; color: var(--baobab-green);">What we do NOT collect</h2>
    <ul style="margin: 10px 0 0 20px;">
        <?php foreach ($not_collected as $item): ?>
            <li style="padding: 4px 0;"><?= htmlspecialchars($item) ?></li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="card">
    <h2 style="margin-top:0;">Your rights under Ghana's Data Protection Act (2012)</h2>
    <ul style="margin: 10px 0 0 20px;">
        <li style="padding: 6px 0;"><strong>Right to access</strong> — You can see everything we have on this page.</li>
        <li style="padding: 6px 0;"><strong>Right to export</strong> — <a href="exportar.php">Download all your data as a ZIP file</a>.</li>
        <li style="padding: 6px 0;"><strong>Right to correction</strong> — Email us to correct inaccurate data.</li>
        <li style="padding: 6px 0;"><strong>Right to erasure</strong> — <a href="eliminar_cuenta.php">Delete your account permanently</a>.</li>
        <li style="padding: 6px 0;"><strong>Right to withdraw consent</strong> — Deleting your account withdraws all consent.</li>
        <li style="padding: 6px 0;"><strong>Right to complain</strong> — You may contact Ghana's Data Protection Commission.</li>
    </ul>
</div>

<div class="card">
    <h2 style="margin-top:0;">Where your data lives</h2>
    <p>All data on Qarota is stored on servers physically located in <strong>Ghana</strong>.</p>
    <p style="margin-top: 8px;">We do not transfer your data outside Ghana. We do not sell it. We do not use it for advertising.</p>
</div>

<div class="card">
    <h2 style="margin-top:0;">How to reach us</h2>
    <p>For any privacy question or request, contact: <a href="mailto:privacy@qarota.com">privacy@qarota.com</a></p>
    <p style="margin-top: 8px; color: var(--muted); font-size: 0.9rem;">
        We respond to all data requests within 14 days.
    </p>
</div>

<?php require 'includes/footer.php'; ?>