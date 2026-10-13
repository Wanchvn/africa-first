<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
start_secure_session();

require 'config/db.php';
require 'lang/init.php';
require 'includes/csrf.php';
require 'includes/conversations.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$me = (int)$_SESSION['user_id'];
$stmt = $pdo->prepare(
    "SELECT u.id, u.username, u.display_name, u.avatar
     FROM follows f
     INNER JOIN users u ON u.id = f.following_id
     WHERE f.follower_id = :me
     ORDER BY COALESCE(NULLIF(u.display_name, ''), u.username), u.username"
);
$stmt->execute([':me' => $me]);
$followedUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$groupText = static function (string $key, string $fallback): string {
    $translation = __($key);
    return $translation === $key ? $fallback : $translation;
};

$page_title = $groupText('group_new_title', 'New group');
require 'includes/header.php';
?>

<div class="card">
    <h2><?= $groupText('group_new_title', 'New group') ?></h2>

    <form action="group_create.php" method="POST" id="newGroupForm">
        <?= csrf_field() ?>

        <div class="form-field">
            <label for="group-name"><?= $groupText('group_name_label', 'Group name') ?></label>
            <input
                type="text"
                id="group-name"
                name="group_name"
                maxlength="100"
                required
                placeholder="<?= htmlspecialchars($groupText('group_name_placeholder', 'e.g. Tamale Tech Crew'), ENT_QUOTES, 'UTF-8') ?>"
            >
        </div>

        <div class="form-field member-picker">
            <label for="member-search"><?= $groupText('group_add_members', 'Add members') ?></label>
            <input
                type="search"
                id="member-search"
                class="member-search"
                placeholder="<?= htmlspecialchars($groupText('group_search_placeholder', 'Search people you follow…'), ENT_QUOTES, 'UTF-8') ?>"
                autocomplete="off"
            >

            <p class="field-hint"><?= $groupText('group_selected', 'Selected') ?></p>
            <div class="selected-chips" id="selectedMembers" aria-live="polite"></div>

            <?php if ($followedUsers): ?>
                <div class="member-list" id="memberList">
                    <?php foreach ($followedUsers as $user): ?>
                        <?php
                        $displayName = $user['display_name'] ?: $user['username'];
                        $searchText = mb_strtolower($displayName . ' ' . $user['username']);
                        ?>
                        <label
                            class="member-row"
                            data-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') ?>"
                            data-name="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <input
                                class="member-select-input"
                                type="checkbox"
                                name="member_ids[]"
                                value="<?= (int)$user['id'] ?>"
                            >
                            <?php if (!empty($user['avatar'])): ?>
                                <img
                                    class="avatar avatar-small member-avatar"
                                    src="<?= htmlspecialchars($user['avatar'], ENT_QUOTES, 'UTF-8') ?>"
                                    alt=""
                                >
                            <?php else: ?>
                                <span class="avatar avatar-small avatar-placeholder member-avatar">
                                    <?= htmlspecialchars(mb_strtoupper(mb_substr($displayName, 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                            <span>
                                <span class="member-name"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="member-handle">@<?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></span>
                            </span>
                            <span class="member-check" aria-hidden="true"></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="field-hint" id="noMatchingMembers" hidden>No people match your search.</p>
            <?php else: ?>
                <p class="field-hint">Follow at least one person to add them to a group.</p>
            <?php endif; ?>
        </div>

        <p class="field-hint" id="memberSelectionError" role="alert" hidden>
            Select at least one person to create a group.
        </p>

        <button type="submit" class="btn"><?= $groupText('group_create_button', 'Create group') ?></button>
    </form>
</div>

<?php if ($followedUsers): ?>
<script>
(function () {
    var form = document.getElementById('newGroupForm');
    var search = document.getElementById('member-search');
    var rows = Array.from(document.querySelectorAll('.member-row'));
    var chips = document.getElementById('selectedMembers');
    var noMatches = document.getElementById('noMatchingMembers');
    var selectionError = document.getElementById('memberSelectionError');

    function renderSelected() {
        chips.replaceChildren();

        rows.forEach(function (row) {
            var checkbox = row.querySelector('.member-select-input');
            row.classList.toggle('selected', checkbox.checked);
            if (!checkbox.checked) return;

            var chip = document.createElement('span');
            chip.className = 'chip';

            var avatar = row.querySelector('.member-avatar').cloneNode(true);
            avatar.classList.remove('avatar-small');
            avatar.classList.add('avatar-tiny');
            chip.appendChild(avatar);

            var name = document.createElement('span');
            name.textContent = row.dataset.name;
            chip.appendChild(name);

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'chip-remove';
            remove.textContent = '×';
            remove.setAttribute('aria-label', 'Remove ' + row.dataset.name);
            remove.addEventListener('click', function () {
                checkbox.checked = false;
                renderSelected();
            });
            chip.appendChild(remove);

            chips.appendChild(chip);
        });

        selectionError.hidden = rows.some(function (row) {
            return row.querySelector('.member-select-input').checked;
        });
    }

    search.addEventListener('input', function () {
        var query = search.value.trim().toLocaleLowerCase();
        var visibleCount = 0;

        rows.forEach(function (row) {
            var matches = row.dataset.search.indexOf(query) !== -1;
            row.hidden = !matches;
            if (matches) visibleCount++;
        });

        noMatches.hidden = visibleCount !== 0;
    });

    rows.forEach(function (row) {
        row.querySelector('.member-select-input').addEventListener('change', renderSelected);
    });

    form.addEventListener('submit', function (event) {
        var hasSelection = rows.some(function (row) {
            return row.querySelector('.member-select-input').checked;
        });

        if (!hasSelection) {
            event.preventDefault();
            selectionError.hidden = false;
        }
    });
})();
</script>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>
