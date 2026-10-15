</main>

<?php if (isset($_SESSION['user_id'])): ?>
    <div class="data-footer">
        Qarota · Your data stays in Ghana
        <br>
        <a href="privacy.php"><?= __('nav_privacy') ?></a> ·
        <a href="moderation.php"><?= __('nav_moderation') ?></a> ·
        <a href="transparency.php">Transparency</a> ·
        <a href="export.php"><?= __('profile_export') ?></a> ·
        <a href="privacy_policy.php">Policy</a>
    </div>
<?php endif; ?>

<script src="assets/js/interact.js?v=<?= filemtime(__DIR__ . '/../assets/js/interact.js') ?>"></script>
<script src="assets/js/nav.js?v=<?= filemtime(__DIR__ . '/../assets/js/nav.js') ?>" defer></script>

<?php if (basename($_SERVER['PHP_SELF']) === 'register.php'): ?>
    <script src="assets/js/register.js"></script>
<?php endif; ?>

<!-- PWA: register service worker -->
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('sw.js')
            .then(function (reg) {
                console.log('[pwa] Service worker registered. Scope:', reg.scope);
            })
            .catch(function (err) {
                console.warn('[pwa] Service worker registration failed:', err);
            });
    });
}
</script>

<!-- iOS install hint -->
<script>
(function () {
    var isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);
    var isStandalone = window.navigator.standalone === true;
    var seenKey = 'qarota_ios_hint_seen';

    if (isIos && !isStandalone && !localStorage.getItem(seenKey)) {
        var bar = document.createElement('div');
        bar.style.cssText = 'position:fixed;bottom:0;left:0;right:0;background:#3E2723;color:#fff;padding:12px 16px;font-size:0.9rem;z-index:9999;text-align:center;box-shadow:0 -2px 8px rgba(0,0,0,0.2);';
        bar.innerHTML = 'Add Qarota to your Home Screen: tap <strong>Share</strong> then <strong>Add to Home Screen</strong>. <span style="float:right;cursor:pointer;padding:0 8px;" id="iosHintClose">✕</span>';
        document.body.appendChild(bar);
        document.getElementById('iosHintClose').onclick = function () {
            bar.remove();
            localStorage.setItem(seenKey, '1');
        };
    }
})();
</script>

</body>
</html>