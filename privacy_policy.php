<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';

$page_title = 'Privacy Policy';
require 'includes/header.php';
?>

<div class="legal-page">
    <h1>Privacy Policy</h1>
    <p class="legal-meta">Last updated: October 7, 2026</p>

    <div class="legal-card">
        <h2>The short version</h2>
        <p>
            Qarota collects the minimum data needed to run a social network —
            your email, username, and the content you choose to post. We store
            everything in <strong>Ghana</strong>. We don't sell your data.
            We don't use it for advertising. We don't transfer it outside
            Ghana. You can export or delete everything at any time.
        </p>
        <p>
            This page explains exactly what we collect, why, and what your
            rights are under Ghana's Data Protection Act 2012 (Act 843).
        </p>
    </div>

    <section class="legal-section">
        <h2>1. Who we are</h2>
        <p>
            Qarota is a social network operated in the Republic of Ghana.
            For the purposes of Ghana's Data Protection Act 2012 (Act 843),
            Qarota is the <strong>data controller</strong> — the entity that
            decides what data is collected and why.
        </p>
        <p>
            <strong>Data Protection Officer:</strong>
            <a href="mailto:privacy@qarota.com">privacy@qarota.com</a>
        </p>
    </section>

    <section class="legal-section">
        <h2>2. What we collect</h2>
        <p>
            Qarota collects only what we need. Here is the complete list:
        </p>

        <h3>Account information (required)</h3>
        <ul>
            <li><strong>Email address</strong> — used to identify your account
                and contact you about your account.</li>
            <li><strong>Username</strong> — your public handle on Qarota.</li>
            <li><strong>Password</strong> — stored as a one-way hash. Even we
                cannot read it.</li>
        </ul>

        <h3>Content you create (optional)</h3>
        <ul>
            <li><strong>Posts</strong> — text and photos you choose to share.</li>
            <li><strong>Comments</strong> — replies you make to others.</li>
            <li><strong>Likes and follows</strong> — public records of your
                interactions.</li>
            <li><strong>Profile picture</strong> — if you upload one.</li>
            <li><strong>Bio and profile fields</strong> — if you fill them in.</li>
        </ul>

        <h3>Technical information (automatic)</h3>
        <ul>
            <li><strong>IP address</strong> — used for security, rate limiting,
                and to prevent abuse.</li>
            <li><strong>Session data</strong> — a temporary cookie that keeps
                you logged in.</li>
            <li><strong>Timestamps</strong> — when you created posts,
                comments, or account.</li>
        </ul>

        <h3>What we do NOT collect</h3>
        <ul>
            <li>Phone number (unless you explicitly add it later)</li>
            <li>Location data</li>
            <li>Contacts or address book</li>
            <li>Browsing history outside Qarota</li>
            <li>Device identifiers for advertising</li>
            <li>Biometric data</li>
            <li>Data for ad targeting</li>
        </ul>
    </section>

    <section class="legal-section">
        <h2>3. Why we collect it</h2>
        <p>
            Every piece of data we collect serves one of these purposes:
        </p>
        <ul>
            <li><strong>To run your account</strong> — you need an email and
                password to log in.</li>
            <li><strong>To display your content</strong> — posts, comments,
                and photos must be stored somewhere.</li>
            <li><strong>To keep the platform safe</strong> — rate limiting
                uses IP addresses to prevent spam and abuse.</li>
            <li><strong>To notify you</strong> — when someone interacts with
                you, we need to know you exist.</li>
            <li><strong>To comply with the law</strong> — Ghana's Data
                Protection Act requires us to notify you about our data
                practices.</li>
        </ul>
        <p>
            <strong>We do not collect data for:</strong> advertising,
            profiling, algorithmic recommendation, third-party analytics,
            tracking, or sale to anyone.
        </p>
    </section>

    <section class="legal-section">
        <h2>4. Legal basis for processing</h2>
        <p>
            Under Ghana's Data Protection Act, we process your data on the
            following legal bases:
        </p>
        <ul>
            <li><strong>Consent</strong> — you agree to these terms when you
                register and tick the consent box.</li>
            <li><strong>Contract</strong> — we need your email and username
                to provide the service you signed up for.</li>
            <li><strong>Legitimate interest</strong> — rate limiting and
                security measures to protect all users.</li>
            <li><strong>Legal obligation</strong> — we may be required by
                Ghanaian law to retain certain records.</li>
        </ul>
    </section>

    <section class="legal-section">
        <h2>5. Where your data is stored</h2>
        <p>
            <strong>All Qarota data is stored on servers physically located
            in Ghana.</strong>
        </p>
        <p>
            We do not transfer your data outside Ghana. We do not route it
            through foreign services. We do not use foreign cloud providers.
        </p>
        <p>
            If we ever need to use infrastructure outside Ghana (for example,
            a content delivery network), we will:
        </p>
        <ul>
            <li>Update this policy first</li>
            <li>Notify all users 30 days in advance</li>
            <li>Give you the option to delete your account before the change</li>
        </ul>
    </section>

    <section class="legal-section">
        <h2>6. How long we keep your data</h2>
        <ul>
            <li><strong>While your account is active</strong> — all your data
                is retained.</li>
            <li><strong>When you delete your account</strong> — all personal
                data is deleted immediately. This includes:
                <ul>
                    <li>Your account record</li>
                    <li>Your posts and photos</li>
                    <li>Your comments and likes</li>
                    <li>Your follows and notifications</li>
                    <li>Your avatar file</li>
                </ul>
            </li>
            <li><strong>Moderation log</strong> — when a post is removed,
                an anonymised entry stays in the public moderation log
                (without your identity). This is necessary for accountability.</li>
            <li><strong>Server logs</strong> — IP addresses in server access
                logs are retained for a maximum of <strong>30 days</strong>
                for security purposes, then automatically deleted.</li>
        </ul>
    </section>

    <section class="legal-section">
        <h2>7. Who we share your data with</h2>
        <p>
            <strong>No one.</strong>
        </p>
        <p>
            We do not share your personal data with third parties. Not for
            advertising, not for analytics, not for marketing, not for
            research, not for anything.
        </p>
        <p>
            <strong>Exceptions:</strong> We will only disclose your data if:
        </p>
        <ul>
            <li>A Ghanaian court orders us to (with a valid warrant or
                court order)</li>
            <li>It is necessary to prevent imminent harm to someone's life
                or safety</li>
            <li>It is required to report child sexual abuse material (CSAM)
                to Ghanaian authorities, which is a legal obligation</li>
        </ul>
        <p>
            In any such case, we will notify you unless legally prohibited
            from doing so.
        </p>
    </section>

    <section class="legal-section">
        <h2>8. Your rights under Act 843</h2>
        <p>
            Ghana's Data Protection Act 2012 gives you the following rights.
            All of them are available to you from your Qarota account:
        </p>
        <ul>
            <li>
                <strong>Right to access</strong> — see everything we have on
                you. Go to <a href="privacy.php">your Privacy page</a>.
            </li>
            <li>
                <strong>Right to correction</strong> — fix inaccurate data.
                Edit your bio and avatar from
                <a href="edit_profile.php">your profile</a>, or email
                <a href="mailto:privacy@qarota.com">privacy@qarota.com</a>.
            </li>
            <li>
                <strong>Right to erasure</strong> — delete everything. Go to
                <a href="delete_account.php">Delete account</a>.
            </li>
            <li>
                <strong>Right to data portability</strong> — download all your
                data as a ZIP file. Go to <a href="export.php">Export my data</a>.
            </li>
            <li>
                <strong>Right to withdraw consent</strong> — deleting your
                account withdraws all consent.</li>
            <li>
                <strong>Right to complain</strong> — contact Ghana's Data
                Protection Commission if you believe we've violated your
                rights.</li>
        </ul>
        <p>
            We respond to all data requests within <strong>14 days</strong>.
            For simple requests (like deletion or export), everything happens
            instantly.
        </p>
    </section>

    <section class="legal-section">
        <h2>9. Cookies and sessions</h2>
        <p>
            Qarota uses a single cookie: <code>PHPSESSID</code>. It is used
            to keep you logged in between page loads.
        </p>
        <p>
            This cookie has the following security settings:
        </p>
        <ul>
            <li><strong>HttpOnly</strong> — JavaScript cannot read it</li>
            <li><strong>SameSite=Lax</strong> — it is not sent on cross-site
                requests</li>
            <li><strong>Secure</strong> — on production, it is sent only over
                HTTPS</li>
        </ul>
        <p>
            <strong>We do not use:</strong> tracking cookies, advertising
            cookies, analytics cookies, or third-party cookies of any kind.
        </p>
    </section>

    <section class="legal-section">
        <h2>10. Data security</h2>
        <p>
            We take security seriously. Qarota implements:
        </p>
        <ul>
            <li>Password hashing with bcrypt (industry standard)</li>
            <li>All database queries using prepared statements</li>
            <li>All user content escaped against script injection</li>
            <li>Cross-Site Request Forgery (CSRF) protection on all forms</li>
            <li>Session hardening with HttpOnly and SameSite cookies</li>
            <li>Rate limiting on sensitive actions</li>
            <li>Security headers (CSP, X-Frame-Options, etc.)</li>
        </ul>
        <p>
            <strong>What to do if there is a breach:</strong> If we ever
            experience a data breach affecting your account, we will notify
            you and the Data Protection Commission within
            <strong>72 hours</strong>.
        </p>
    </section>

    <section class="legal-section">
        <h2>11. Children</h2>
        <p>
            Qarota is not intended for children under <strong>16 years
            old</strong>. We do not knowingly collect data from anyone under
            16.
        </p>
        <p>
            If we learn that a user is under 16, we will:
        </p>
        <ul>
            <li>Immediately delete their account</li>
            <li>Delete all associated data</li>
            <li>Notify their parent or guardian if we have contact
                information</li>
        </ul>
        <p>
            If you believe a user is under 16, please report them via
            <a href="mailto:abuse@qarota.com">abuse@qarota.com</a>.
        </p>
    </section>

    <section class="legal-section">
        <h2>12. Changes to this policy</h2>
        <p>
            If we change this Privacy Policy, we will:
        </p>
        <ul>
            <li>Post the changes on this page with a new "Last updated" date</li>
            <li>Show a notification in your feed</li>
            <li>Give you 30 days to review them</li>
        </ul>
        <p>
            If you don't agree with the changes, you can delete your account
            before they take effect.
        </p>
    </section>

    <section class="legal-section">
        <h2>13. Contact us</h2>
        <p>
            For any question about this policy or your data:
        </p>
        <ul>
            <li><strong>General privacy questions:</strong>
                <a href="mailto:privacy@qarota.com">privacy@qarota.com</a></li>
            <li><strong>Report abuse:</strong>
                <a href="report.php">Report form</a> or
                <a href="mailto:abuse@qarota.com">abuse@qarota.com</a></li>
            <li><strong>Complaint to the DPC:</strong>
                You can contact Ghana's Data Protection Commission at
                <a href="https://dataprotection.org.gh" target="_blank" rel="noopener">dataprotection.org.gh</a></li>
        </ul>
    </section>

    <div class="legal-footer">
        <p>
            This policy is written in plain language. If anything is unclear,
            please <a href="mailto:privacy@qarota.com">email us</a>.
        </p>
        <p>
            <a href="terms.php">Read our Terms of Service →</a>
        </p>
    </div>
</div>

<?php require 'includes/footer.php'; ?>