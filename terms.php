<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';

$page_title = 'Terms of Service';
require 'includes/header.php';
?>

<div class="legal-page">
    <h1>Terms of Service</h1>
    <p class="legal-meta">Last updated: October 7, 2026</p>

    <div class="legal-card">
        <h2>In plain language</h2>
        <p>
            Qarota is a social network for Africa, built in Ghana.
            We exist to give people a platform where their data stays home,
            their voice can't be silenced without reason, and every
            moderation action is public.
        </p>
        <p>
            These Terms explain what you can expect from us and what we
            expect from you. If anything is unclear, email
            <a href="mailto:legal@qarota.com">legal@qarota.com</a>.
        </p>
    </div>

    <section class="legal-section">
        <h2>1. Who can use Qarota</h2>
        <p>
            You can use Qarota if you are at least <strong>16 years old</strong>
            and can enter into a binding agreement. If you are under 16,
            please do not create an account.
        </p>
        <p>
            You are responsible for keeping your password safe. If you believe
            someone else has access to your account, change your password
            immediately.
        </p>
    </section>

    <section class="legal-section">
        <h2>2. Your account</h2>
        <p>
            You own your account. You can:
        </p>
        <ul>
            <li><strong>Export</strong> all your data at any time — from
                <a href="export.php">your profile</a>.</li>
            <li><strong>Delete</strong> your account permanently — from
                <a href="delete_account.php">your profile</a>.</li>
            <li><strong>Change</strong> your bio, avatar, and settings whenever
                you want.</li>
        </ul>
        <p>
            We do not sell your data. We do not use it for advertising. We do
            not transfer it outside Ghana.
        </p>
    </section>

    <section class="legal-section">
        <h2>3. What you can post</h2>
        <p>You can post almost anything. We ask that you don't post:</p>
        <ul>
            <li><strong>Child sexual abuse material</strong> (CSAM) — this is
                illegal and we will report it to authorities.</li>
            <li><strong>Content that incites violence</strong> against
                individuals or groups.</li>
            <li><strong>Terrorist content</strong> or material promoting
                terrorist organizations.</li>
            <li><strong>Non-consensual intimate images</strong> of any
                person.</li>
            <li><strong>Fraud, scams, or phishing</strong> attempts.</li>
            <li><strong>Targeted harassment</strong> of specific people.</li>
        </ul>
        <p>
            Everything else is welcome. Political opinions, religious views,
            criticism of Qarota, criticism of your government — all allowed.
        </p>
    </section>

    <section class="legal-section">
        <h2>4. How we moderate</h2>
        <p>
            Qarota is moderated by humans, not algorithms. When someone reports
            a post:
        </p>
        <ol>
            <li>A human reviews it (usually within 48 hours).</li>
            <li>If it violates our rules, the post is removed.</li>
            <li>If it doesn't, the report is dismissed.</li>
            <li>
                <strong>Every decision is logged publicly</strong> at
                <a href="moderation.php">Moderation Log</a>.
            </li>
        </ol>
        <p>
            We never remove content in secret. We never shadow-ban. If your
            post is removed, you will know exactly why.
        </p>
    </section>

    <section class="legal-section">
        <h2>5. Your data, your rights</h2>
        <p>
            Under Ghana's Data Protection Act 2012 (Act 843), you have the
            right to:
        </p>
        <ul>
            <li><strong>Access</strong> everything we know about you — see
                <a href="privacy.php">your Privacy page</a>.</li>
            <li><strong>Correct</strong> inaccurate data — email
                <a href="mailto:privacy@qarota.com">privacy@qarota.com</a>.</li>
            <li><strong>Delete</strong> your data — from
                <a href="delete_account.php">your profile</a>.</li>
            <li><strong>Export</strong> your data — from
                <a href="export.php">your profile</a>.</li>
            <li><strong>Complain</strong> to the Data Protection Commission
                if you believe your rights have been violated.</li>
        </ul>
        <p>
            <strong>Where your data lives:</strong> All Qarota data is stored
            on servers physically located in Ghana. We do not transfer it
            outside Ghana. We do not sell it. We do not use it for advertising.
        </p>
    </section>

    <section class="legal-section">
        <h2>6. What we don't do</h2>
        <p>
            We want to be explicit about the things Qarota does <em>not</em> do,
            because these are the reasons many people come to us:
        </p>
        <ul>
            <li>We don't sell your data to advertisers.</li>
            <li>We don't use algorithms to maximize your time on the app.</li>
            <li>We don't shadow-ban or secretly limit your reach.</li>
            <li>We don't collect your phone number, location, or contacts
                unless you explicitly give them.</li>
            <li>We don't track you across other websites.</li>
            <li>We don't remove content without a public log entry.</li>
            <li>We don't lock you out of your own data.</li>
        </ul>
    </section>

    <section class="legal-section">
        <h2>7. Copyright</h2>
        <p>
            When you post something on Qarota, you keep the copyright. You
            grant us a limited license to display your content to other users.
            That's it.
        </p>
        <p>
            If you believe someone has posted content that infringes your
            copyright, report it via the <a href="report.php">report form</a>
            or email <a href="mailto:legal@qarota.com">legal@qarota.com</a>.
        </p>
    </section>

    <section class="legal-section">
        <h2>8. Our liability</h2>
        <p>
            Qarota is provided "as is." We work hard to keep it running, but
            we cannot promise uninterrupted service. We are not responsible
            for what users post — only for our own moderation decisions.
        </p>
        <p>
            We are not liable for indirect damages, lost profits, or
            consequential losses. If you have a serious problem with Qarota,
            your remedy is to stop using it or delete your account.
        </p>
    </section>

    <section class="legal-section">
        <h2>9. Changes to these terms</h2>
        <p>
            If we change these Terms, we'll:
        </p>
        <ul>
            <li>Post the changes publicly.</li>
            <li>Show a notification in your feed.</li>
            <li>Give you 30 days to review them before they take effect.</li>
        </ul>
        <p>
            If you don't agree with the new Terms, you can delete your account
            at any time.
        </p>
    </section>

    <section class="legal-section">
        <h2>10. Governing law</h2>
        <p>
            These Terms are governed by the laws of the
            <strong>Republic of Ghana</strong>. Any dispute will be resolved
            in Ghanaian courts.
        </p>
    </section>

    <section class="legal-section">
        <h2>11. Contact</h2>
        <p>
            For any question about these Terms:
        </p>
        <ul>
            <li><strong>General:</strong>
                <a href="mailto:legal@qarota.com">legal@qarota.com</a></li>
            <li><strong>Privacy and data:</strong>
                <a href="mailto:privacy@qarota.com">privacy@qarota.com</a></li>
            <li><strong>Report abuse:</strong>
                <a href="report.php">Report form</a> or
                <a href="mailto:abuse@qarota.com">abuse@qarota.com</a></li>
        </ul>
    </section>

    <div class="legal-footer">
        <p>
            By using Qarota, you agree to these Terms.
        </p>
        <p>
            <a href="privacy_policy.php">Read our Privacy Policy →</a>
        </p>
    </div>
</div>

<?php require 'includes/footer.php'; ?>