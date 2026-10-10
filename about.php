<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();
require 'lang/init.php';
$page_title = 'About';
require 'includes/header.php';
?>

<div class="legal-page">
    <h1>About Qarota</h1>
    <p class="legal-meta">Built in Ghana.</p>

    <div class="legal-section">
        <h2>Why we built this</h2>
        <p>
            Africa has over a billion people and almost no social network of its own.
            Every conversation, every photo, every voice note travels through servers
            we don't control, governed by laws we didn't write.
        </p>
        <p>
            Qarota is a small attempt to change that.
        </p>
    </div>

    <div class="legal-section">
        <h2>What we believe</h2>
        <ul>
            <li><strong>Your data is yours.</strong> It stays on servers in Ghana. You can export all of it, any time.</li>
            <li><strong>Moderation should be public.</strong> Every post removal, every dismissed report is logged where anyone can read it.</li>
            <li><strong>Your language matters.</strong> Twi, Dagbani, English and French are first-class on Qarota — not afterthoughts.</li>
            <li><strong>No algorithms.</strong> You decide who to follow. You see what they post. That's it.</li>
        </ul>
    </div>

    <div class="legal-section">
        <h2>Who we are</h2>
        <p>
            Qarota is built by a small team in Tamale, Ghana.
            We're a computer science student, a few developers, and a growing
            community of early users who believe Africa deserves its own digital home.
        </p>
    </div>

    <div class="legal-section">
        <h2>Contact</h2>
        <p>
            Say hello: <a href="mailto:hello@qarota.com">hello@qarota.com</a>
        </p>
    </div>
</div>

<?php require 'includes/footer.php'; ?>