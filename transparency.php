<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require_once __DIR__ . '/includes/transparency.php';

// Ensure the current month's snapshot exists
try {
    ensure_current_month_snapshot($pdo);
} catch (Throwable $e) {
    error_log('transparency snapshot error: ' . $e->getMessage());
}

// Load the last 12 months of snapshots
$stmt = $pdo->query("
    SELECT * FROM transparency_snapshots
    ORDER BY month DESC
    LIMIT 12
");
$snapshots = $stmt->fetchAll(PDO::FETCH_ASSOC);

// This month is the newest
$this_month = $snapshots[0] ?? null;

// Lifetime totals
$stmt = $pdo->query("SELECT COUNT(*) FROM users");
$total_users = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM posts");
$total_posts = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM moderation_log");
$total_actions = (int)$stmt->fetchColumn();

// Government requests — lifetime (should always be 0)
$stmt = $pdo->query("SELECT COALESCE(SUM(government_requests), 0) FROM transparency_snapshots");
$total_gov_requests = (int)$stmt->fetchColumn();

$page_title = 'Transparency Report';
require 'includes/header.php';
?>

<div class="container" style="max-width: 820px;">

    <div class="transparency-hero">
        <h1>Transparency Report</h1>
        <p class="transparency-tagline">
            Every number on this page is real. No spin. No "industry standard" handwaving.
        </p>
    </div>

    <div class="card transparency-headline">
        <div class="transparency-headline-grid">
            <div class="transparency-stat">
                <div class="transparency-stat-number"><?= $total_gov_requests ?></div>
                <div class="transparency-stat-label">Government data requests</div>
                <div class="transparency-stat-note">Qarota is based in Ghana. To date, no government has asked us for user data.</div>
            </div>
            <div class="transparency-stat">
                <div class="transparency-stat-number">Ghana</div>
                <div class="transparency-stat-label">Server location</div>
                <div class="transparency-stat-note">User data does not leave Ghana. It is not routed through the US or EU.</div>
            </div>
            <div class="transparency-stat">
                <div class="transparency-stat-number"><?= number_format($total_actions) ?></div>
                <div class="transparency-stat-label">Public moderation actions</div>
                <div class="transparency-stat-note">Every removal, ban, appeal, and verification is on the record.</div>
            </div>
        </div>
    </div>

    <?php if ($this_month): ?>
        <h2>This month — <?= date('F Y', strtotime($this_month['month'])) ?></h2>

        <div class="card">
            <div class="transparency-month-grid">
                <div class="transparency-month-item">
                    <div class="tm-value"><?= (int)$this_month['posts_removed'] ?></div>
                    <div class="tm-label">Posts removed</div>
                </div>
                <div class="transparency-month-item">
                    <div class="tm-value"><?= (int)$this_month['users_banned'] ?></div>
                    <div class="tm-label">Users banned</div>
                </div>
                <div class="transparency-month-item">
                    <div class="tm-value"><?= (int)$this_month['reports_received'] ?></div>
                    <div class="tm-label">Reports received</div>
                </div>
                <div class="transparency-month-item">
                    <div class="tm-value"><?= (int)$this_month['appeals_received'] ?></div>
                    <div class="tm-label">Appeals received</div>
                </div>
                <div class="transparency-month-item">
                    <div class="tm-value"><?= (int)$this_month['verifications_approved'] ?></div>
                    <div class="tm-label">Verifications approved</div>
                </div>
                <div class="transparency-month-item">
                    <div class="tm-value"><?= (int)$this_month['new_users'] ?></div>
                    <div class="tm-label">New users</div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <h2>History</h2>

    <?php if (empty($snapshots)): ?>
        <div class="empty">No data yet.</div>
    <?php else: ?>
        <div class="card" style="overflow-x:auto;">
            <table class="transparency-table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Posts removed</th>
                        <th>Users banned</th>
                        <th>Reports</th>
                        <th>Appeals</th>
                        <th>Verifications</th>
                        <th>Gov. requests</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($snapshots as $s): ?>
                        <tr>
                            <td><strong><?= date('M Y', strtotime($s['month'])) ?></strong></td>
                            <td><?= (int)$s['posts_removed'] ?></td>
                            <td><?= (int)$s['users_banned'] ?></td>
                            <td><?= (int)$s['reports_received'] ?></td>
                            <td><?= (int)$s['appeals_approved'] + (int)$s['appeals_rejected'] ?></td>
                            <td><?= (int)$s['verifications_approved'] + (int)$s['verifications_rejected'] ?></td>
                            <td><strong><?= (int)$s['government_requests'] ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h2>What we don't do</h2>

    <div class="card">
        <ul class="transparency-list">
            <li><strong>We don't sell your data.</strong> Not to advertisers, not to data brokers, not to anyone. This has never happened and never will.</li>
            <li><strong>We don't track you across the web.</strong> No pixels, no third-party analytics, no shadow profiles.</li>
            <li><strong>We don't run an algorithm on your feed.</strong> You see posts from people you follow, in chronological order. That's it.</li>
            <li><strong>We don't ban in silence.</strong> Every ban comes with a reason, a right to appeal, and a public log entry.</li>
            <li><strong>We don't store your ID longer than we need it.</strong> Government ID documents submitted for verification are permanently deleted 30 days after your application is resolved.</li>
            <li><strong>We don't route data outside Ghana.</strong> Servers are in Ghana. Backups are in Ghana.</li>
        </ul>
    </div>

    <h2>About this report</h2>

    <div class="card">
        <p>
            This transparency report is generated from the same database that runs the platform.
            The numbers here are computed from real rows in <code>moderation_log</code>,
            <code>reports</code>, <code>appeals</code>, and <code>verification_applications</code>.
        </p>
        <p>
            If you see a discrepancy between what this page says and what you've experienced,
            <a href="report.php">report it</a>. That's the whole point of a transparency report.
        </p>
        <p style="color: var(--muted); font-size: 0.85rem;">
            See every individual action in the <a href="moderation.php">moderation log</a>.
        </p>
    </div>

</div>

<?php require 'includes/footer.php'; ?>