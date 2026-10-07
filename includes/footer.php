</main>

<?php if (isset($_SESSION['user_id'])): ?>
    <div class="data-footer">
        Qarota · Your data stays in Ghana
        <br>
        <a href="privacy.php"><?= __('nav_privacy') ?></a> ·
        <a href="moderation.php"><?= __('nav_moderation') ?></a> ·
        <a href="export.php"><?= __('profile_export') ?></a>
    </div>
<?php endif; ?>

<script src="assets/js/interact.js"></script>

<?php if (basename($_SERVER['PHP_SELF']) === 'register.php'): ?>
    <script src="assets/js/register.js"></script>
<?php endif; ?>

</body>
</html>