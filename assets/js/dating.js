/* ============================================================
   QAROTA — Dating swipe deck
   ============================================================ */
(function () {
    'use strict';

    var deck = document.getElementById('swipeDeck');
    if (!deck) return;

    var card = document.getElementById('swipeCard');
    var matchModal = document.getElementById('matchModal');
    var csrfToken = deck.dataset.csrf;
    var currentId = parseInt(deck.dataset.candidateId, 10);
    var busy = false;

    var passBtn = deck.querySelector('.swipe-pass');
    var likeBtn = deck.querySelector('.swipe-like');

    function setBusy(state) {
        busy = state;
        if (passBtn) passBtn.disabled = state;
        if (likeBtn) likeBtn.disabled = state;
    }

    function animateOut(direction, cb) {
        if (!card) return cb();
        var tx = direction === 'like' ? '120%' : '-120%';
        var rot = direction === 'like' ? '18deg' : '-18deg';
        card.style.transition = 'transform 0.28s ease, opacity 0.28s ease';
        card.style.transform = 'translateX(' + tx + ') rotate(' + rot + ')';
        card.style.opacity = '0';
        setTimeout(cb, 300);
    }

    function reload() {
        window.location.reload();
    }

    function showMatch(matchData) {
        if (!matchModal || !matchData) return reload();

        // Build avatar row from both sides
        var row = document.getElementById('matchAvatars');
        if (row) {
            row.innerHTML = '';
            // current user's avatar is in the header; just show theirs
            // (both sides get the modal so this is fine)
            var theirAvatar = card.querySelector('.swipe-card-photo img');
            if (theirAvatar) {
                var img = document.createElement('img');
                img.src = theirAvatar.src;
                img.className = 'match-avatar';
                img.alt = '';
                row.appendChild(img);
            }
        }

        var messageBtn = document.getElementById('matchMessageBtn');
        if (messageBtn && matchData.conversation_id) {
            messageBtn.href = 'conversation.php?id=' + matchData.conversation_id;
        }

        matchModal.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function sendSwipe(action) {
        if (busy) return;
        setBusy(true);

        var body = new URLSearchParams({
            target_id: currentId,
            action: action,
            csrf_token: csrfToken
        });

        fetch('dating_swipe.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body
        })
        .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
        .then(function (res) {
            if (!res.data.success) {
                alert(res.data.error || 'Something went wrong.');
                setBusy(false);
                return;
            }

            // If liked and matched, show modal; otherwise reload to next card.
            animateOut(action, function () {
                if (res.data.matched && res.data.match) {
                    showMatch(res.data.match);
                } else {
                    reload();
                }
            });
        })
        .catch(function (err) {
            console.error('swipe error', err);
            alert('Network error. Try again.');
            setBusy(false);
        });
    }

    if (passBtn) passBtn.addEventListener('click', function () { sendSwipe('pass'); });
    if (likeBtn) likeBtn.addEventListener('click', function () { sendSwipe('like'); });

    var keepSwiping = document.getElementById('matchKeepSwiping');
    if (keepSwiping) {
        keepSwiping.addEventListener('click', function () {
            matchModal.hidden = true;
            document.body.style.overflow = '';
            reload();
        });
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', function (e) {
        if (busy) return;
        if (e.target.tagName === 'TEXTAREA' || e.target.tagName === 'INPUT') return;
        if (e.key === 'ArrowLeft') sendSwipe('pass');
        if (e.key === 'ArrowRight') sendSwipe('like');
    });
})();