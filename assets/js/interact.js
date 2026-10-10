/* ========================================
   QAROTA — AJAX Interactions
   ======================================== */

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.like-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            handleLike(form);
        });
    });

    document.querySelectorAll('.follow-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            handleFollow(form);
        });
    });

    document.querySelectorAll('.bookmark-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            handleBookmark(form);
        });
    });
});

/* ---- LIKE HANDLER ---- */
function handleLike(form) {
    const button = form.querySelector('button');
    const postId = form.querySelector('input[name="post_id"]').value;
    const csrfToken = form.querySelector('input[name="csrf_token"]').value;

    button.disabled = true;

    fetch('interact.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            action: 'like',
            post_id: postId,
            csrf_token: csrfToken,
            redirect: window.location.pathname + window.location.search
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const heart = button.querySelector('.like-heart');
            const count = button.querySelector('.like-count');

            if (data.liked) {
                button.classList.add('liked');
                heart.textContent = '♥';
            } else {
                button.classList.remove('liked');
                heart.textContent = '♡';
            }

            count.textContent = data.like_count;
        } else {
            alert(data.error || 'Something went wrong');
        }
        button.disabled = false;
    })
    .catch(error => {
        console.error('Error:', error);
        button.disabled = false;
        form.submit();
    });
}

/* ---- FOLLOW HANDLER ---- */
function handleFollow(form) {
    const button = form.querySelector('button');
    const targetId = form.querySelector('input[name="target_id"]').value;
    const csrfToken = form.querySelector('input[name="csrf_token"]').value;

    button.disabled = true;
    const originalText = button.textContent;

    fetch('follow.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            target_id: targetId,
            csrf_token: csrfToken,
            redirect: window.location.pathname + window.location.search
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.state === 'followed') {
                button.textContent = button.dataset.unfollowText || 'Unfollow';
                button.classList.add('btn-secondary');
            } else {
                button.textContent = button.dataset.followText || 'Follow';
                button.classList.remove('btn-secondary');
            }
        } else {
            alert(data.error || 'Something went wrong');
            button.textContent = originalText;
        }
        button.disabled = false;
    })
    .catch(error => {
        console.error('Error:', error);
        button.disabled = false;
        button.textContent = originalText;
        form.submit();
    });
}

/* ---- BOOKMARK HANDLER ---- */
function handleBookmark(form) {
    const button = form.querySelector('button');
    const postId = form.querySelector('input[name="post_id"]').value;
    const csrfToken = form.querySelector('input[name="csrf_token"]').value;

    button.disabled = true;

    fetch('interact.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            action: 'bookmark',
            post_id: postId,
            csrf_token: csrfToken,
            redirect: window.location.pathname + window.location.search
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.bookmarked) {
                button.classList.add('bookmarked');
            } else {
                button.classList.remove('bookmarked');
            }
        }
        button.disabled = false;
    })
    .catch(error => {
        console.error('Error:', error);
        button.disabled = false;
        form.submit();
    });
}


/* ========================================
   HOVER CARDS
   ======================================== */

(function() {
    const cardCache = {};
    let hoverTimer = null;
    let hideTimer = null;
    let currentCard = null;
    let currentUserId = null;

    function getCardElement() {
        if (currentCard) return currentCard;
        const card = document.createElement('div');
        card.className = 'user-hover-card';
        card.style.display = 'none';
        document.body.appendChild(card);
        currentCard = card;
        return card;
    }

    document.addEventListener('mouseover', function(e) {
        const link = e.target.closest('a[data-user-id]');
        if (!link) return;
        const userId = link.dataset.userId;
        if (!userId) return;
        if (currentUserId === userId && currentCard && currentCard.style.display === 'block') return;

        clearTimeout(hideTimer);
        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(function() { showCard(userId, link); }, 300);
    });

    document.addEventListener('mouseout', function(e) {
        const link = e.target.closest('a[data-user-id]');
        if (!link) return;
        clearTimeout(hoverTimer);
        hideTimer = setTimeout(function() { hideCard(); }, 200);
    });

    document.addEventListener('mouseover', function(e) {
        if (currentCard && currentCard.contains(e.target)) clearTimeout(hideTimer);
    });

    document.addEventListener('mouseout', function(e) {
        if (currentCard && currentCard.contains(e.target)) {
            const toElement = e.relatedTarget;
            if (!currentCard.contains(toElement)) hideTimer = setTimeout(hideCard, 200);
        }
    });

    function hideCard() {
        if (currentCard) currentCard.style.display = 'none';
        currentUserId = null;
    }

    function showCard(userId, anchorEl) {
        currentUserId = userId;
        if (cardCache[userId]) { renderCard(cardCache[userId], anchorEl); return; }

        const card = getCardElement();
        card.innerHTML = '<div style="padding: 16px; text-align: center; color: var(--muted);">Loading…</div>';
        positionCard(card, anchorEl);
        card.style.display = 'block';

        fetch('user_card.php?id=' + userId)
            .then(r => r.json())
            .then(data => {
                if (!data.success) { hideCard(); return; }
                cardCache[userId] = data.user;
                if (currentUserId === userId) renderCard(data.user, anchorEl);
            })
            .catch(() => { hideCard(); });
    }

    function renderCard(user, anchorEl) {
        const card = getCardElement();

        if (user.is_blocked) {
            const avatarHtmlB = user.avatar
                ? `<img src="${escapeHtml(user.avatar)}" alt="" class="user-hover-avatar">`
                : `<div class="user-hover-avatar user-hover-avatar-placeholder">${escapeHtml(user.display_name[0].toUpperCase())}</div>`;

            card.innerHTML = `
                <div class="user-hover-header">
                    ${avatarHtmlB}
                    <div class="user-hover-info">
                        <a href="profile.php?u=${encodeURIComponent(user.username)}" class="user-hover-name">
                            ${escapeHtml(user.display_name)}
                        </a>
                        <div class="user-hover-handle">@${escapeHtml(user.username)}</div>
                    </div>
                </div>
                <p class="user-hover-bio" style="font-style:italic;">
                    You cannot interact with this user.
                </p>
            `;
            positionCard(card, anchorEl);
            card.style.display = 'block';
            return;
        }

        const avatarHtml = user.avatar
            ? `<img src="${escapeHtml(user.avatar)}" alt="" class="user-hover-avatar">`
            : `<div class="user-hover-avatar user-hover-avatar-placeholder">${escapeHtml(user.display_name[0].toUpperCase())}</div>`;

        const bioHtml = user.bio ? `<p class="user-hover-bio">${escapeHtml(user.bio)}</p>` : '';

        const buttonHtml = user.is_self ? '' : `
            <form method="POST" action="follow.php" class="follow-form user-hover-follow-form">
                <input type="hidden" name="csrf_token" value="${getCsrfToken()}">
                <input type="hidden" name="target_id" value="${user.id}">
                <input type="hidden" name="redirect" value="${escapeHtml(window.location.pathname + window.location.search)}">
                <button type="submit"
                        class="${user.is_following ? 'btn-secondary' : 'btn'} btn-small"
                        data-follow-text="Follow"
                        data-unfollow-text="Unfollow">
                    ${user.is_following ? 'Unfollow' : 'Follow'}
                </button>
            </form>
        `;

        card.innerHTML = `
            <div class="user-hover-header">
                ${avatarHtml}
                <div class="user-hover-info">
                    <a href="profile.php?u=${encodeURIComponent(user.username)}" class="user-hover-name">
                        ${escapeHtml(user.display_name)}
                    </a>
                    <div class="user-hover-handle">@${escapeHtml(user.username)}</div>
                </div>
            </div>
            <div class="user-hover-stats">
                <strong>${user.follower_count}</strong> ${user.follower_count == 1 ? 'follower' : 'followers'}
                · <strong>${user.following_count}</strong> following
            </div>
            ${bioHtml}
            ${buttonHtml}
        `;

        positionCard(card, anchorEl);
        card.style.display = 'block';

        const form = card.querySelector('.follow-form');
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                handleFollow(form);
                const btn = form.querySelector('button');
                setTimeout(function() {
                    const wasFollowing = btn.classList.contains('btn-secondary');
                    if (wasFollowing) {
                        btn.classList.remove('btn-secondary'); btn.classList.add('btn'); btn.textContent = 'Follow';
                    } else {
                        btn.classList.add('btn-secondary'); btn.classList.remove('btn'); btn.textContent = 'Unfollow';
                    }
                    if (cardCache[user.id]) cardCache[user.id].is_following = !wasFollowing;
                }, 100);
            });
        }
    }

    function positionCard(card, anchorEl) {
        const rect = anchorEl.getBoundingClientRect();
        const cardWidth = 300;
        let left = rect.left + window.scrollX;
        let top = rect.bottom + window.scrollY + 8;
        if (left + cardWidth > window.scrollX + window.innerWidth - 16) left = window.scrollX + window.innerWidth - cardWidth - 16;
        if (left < window.scrollX + 16) left = window.scrollX + 16;
        card.style.left = left + 'px';
        card.style.top = top + 'px';
        card.style.width = cardWidth + 'px';
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function getCsrfToken() {
        const input = document.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    }
})();


/* ========================================
   COMMENT EDITING
   ======================================== */

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.comment-edit-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            startEditComment(btn);
        });
    });
});

function startEditComment(btn) {
    const comment = btn.closest('.comment');
    if (!comment) return;

    const commentId = btn.dataset.commentId;
    const bodyEl = comment.querySelector('.comment-body');
    if (!bodyEl) return;

    const originalContent = bodyEl.textContent.trim();

    bodyEl.innerHTML = `
        <form class="comment-edit-form" onsubmit="return false;">
            <textarea maxlength="500" required>${escapeHtmlJs(originalContent)}</textarea>
            <div class="comment-edit-actions">
                <button type="button" class="btn btn-small comment-save-btn">Save</button>
                <button type="button" class="btn-secondary btn-small comment-cancel-btn">Cancel</button>
                <span class="comment-edit-error" style="color: var(--danger); font-size: 0.8rem; margin-left: auto;"></span>
            </div>
        </form>
    `;

    const textarea = bodyEl.querySelector('textarea');
    textarea.focus();
    textarea.setSelectionRange(textarea.value.length, textarea.value.length);

    const saveBtn = bodyEl.querySelector('.comment-save-btn');
    const cancelBtn = bodyEl.querySelector('.comment-cancel-btn');
    const errorEl = bodyEl.querySelector('.comment-edit-error');

    saveBtn.addEventListener('click', function() {
        const newContent = textarea.value.trim();
        if (newContent === '') { errorEl.textContent = 'Comment cannot be empty'; return; }
        if (newContent === originalContent) { errorEl.textContent = 'No changes made'; return; }

        saveBtn.disabled = true;
        errorEl.textContent = '';

        const csrfInput = document.querySelector('input[name="csrf_token"]');
        const csrfToken = csrfInput ? csrfInput.value : '';

        fetch('edit_comment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ comment_id: commentId, content: newContent, csrf_token: csrfToken })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                bodyEl.innerHTML = escapeHtmlJs(data.content);
                const meta = comment.querySelector('.comment-meta');
                if (meta && !meta.querySelector('.edited-label')) {
                    const editedSpan = document.createElement('span');
                    editedSpan.className = 'edited-label';
                    editedSpan.title = 'Edited ' + data.edited_at;
                    editedSpan.textContent = ' · Edited';
                    meta.appendChild(editedSpan);
                } else if (meta) {
                    const existing = meta.querySelector('.edited-label');
                    if (existing) existing.title = 'Edited ' + data.edited_at;
                }
            } else {
                errorEl.textContent = data.error || 'Could not save';
                saveBtn.disabled = false;
            }
        })
        .catch(function() { errorEl.textContent = 'Network error'; saveBtn.disabled = false; });
    });

    cancelBtn.addEventListener('click', function() { bodyEl.innerHTML = escapeHtmlJs(originalContent); });
}

function escapeHtmlJs(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}


/* ========================================
   MESSAGES — AJAX send + poll + typing + read receipts + voice + images
   ======================================== */

(function () {
    var card = document.getElementById('conversationCard');
    if (!card) return;

    var conv   = card.dataset.conv;
    var me     = parseInt(card.dataset.me, 10);
    var thread = document.getElementById('thread');
    var empty  = document.getElementById('threadEmpty');
    var form   = document.getElementById('messageForm');
    var bodyEl = document.getElementById('messageBody');
    var btn    = document.getElementById('sendBtn');

    if (!form || !thread) return;

    var seenLabel = card.dataset.seenLabel || 'Seen';

    var lastId = 0;
    thread.querySelectorAll('.bubble').forEach(function (el) {
        var id = parseInt(el.dataset.id, 10);
        if (!isNaN(id) && id > lastId) lastId = id;
    });

    function scrollToBottom() { thread.scrollTop = thread.scrollHeight; }

    function updateSeenPill(lastOwnId, lastOwnSeen) {
        var existing = thread.querySelector('.seen-pill');
        if (existing) existing.remove();
        if (!lastOwnId || !lastOwnSeen) return;

        var bubble = thread.querySelector('.bubble[data-id="' + lastOwnId + '"]');
        if (!bubble) return;
        if (bubble.classList.contains('bubble-deleted')) return;

        var timeEl = bubble.querySelector('.bubble-time');
        if (!timeEl) return;

        var pill = document.createElement('span');
        pill.className = 'seen-pill';
        pill.textContent = ' · ' + seenLabel;
        timeEl.appendChild(pill);
    }

    function renderMessage(m) {
        var mine = (parseInt(m.sender_id, 10) === me);
        var isDeleted = !!m.deleted_at;

        var div = document.createElement('div');
        div.className = 'bubble ' + (mine ? 'bubble-mine' : 'bubble-theirs')
                      + (isDeleted ? ' bubble-deleted' : '');
        if (m.id) div.dataset.id = m.id;

        var bodyDiv = document.createElement('div');
        bodyDiv.className = 'bubble-body';

        var isVoice = (m.message_type === 'voice') && m.voice_path;
        var isImage = (m.message_type === 'image') && m.image_path;

        if (isDeleted) {
            var em = document.createElement('em');
            em.className = 'bubble-deleted-text';
            em.textContent = 'Message deleted';
            bodyDiv.appendChild(em);
        } else if (isVoice) {
            var wrap = document.createElement('div');
            wrap.className = 'voice-message';

            var audio = document.createElement('audio');
            audio.controls = true;
            audio.preload = 'metadata';
            audio.className = 'voice-player';
            var src = document.createElement('source');
            src.src = m.voice_path;
            audio.appendChild(src);
            wrap.appendChild(audio);

            var dur = parseInt(m.voice_duration, 10) || 0;
            var durEl = document.createElement('div');
            durEl.className = 'voice-duration';
            durEl.textContent = Math.floor(dur / 60) + ':' + ('0' + (dur % 60)).slice(-2);
            wrap.appendChild(durEl);

            bodyDiv.appendChild(wrap);
        } else if (isImage) {
            var link = document.createElement('a');
            link.className = 'dm-image-link';
            link.href = m.image_path;
            link.target = '_blank';
            link.rel = 'noopener';

            var img = document.createElement('img');
            img.className = 'dm-image';
            img.src = m.image_path;
            img.alt = 'Image';
            img.loading = 'lazy';

            link.appendChild(img);
            bodyDiv.appendChild(link);
        } else {
            var lines = String(m.body).split('\n');
            lines.forEach(function (line, i) {
                if (i > 0) bodyDiv.appendChild(document.createElement('br'));
                bodyDiv.appendChild(document.createTextNode(line));
            });
        }

        var timeDiv = document.createElement('div');
        timeDiv.className = 'bubble-time';
        timeDiv.textContent = m.created_at;

        div.appendChild(bodyDiv);
        div.appendChild(timeDiv);

        if (!mine && !isDeleted) {
            var rep = document.createElement('a');
            rep.className = 'bubble-report';
            rep.href = 'report_message.php?conversation_id=' + encodeURIComponent(conv)
                     + '&message_id=' + encodeURIComponent(m.id);
            rep.title = 'Report';
            rep.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>';
            div.appendChild(rep);
        }

        if (mine && !isDeleted) {
            var del = document.createElement('button');
            del.type = 'button';
            del.className = 'bubble-delete';
            del.dataset.messageId = m.id;
            del.title = 'Delete';
            del.setAttribute('aria-label', 'Delete message');
            del.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>';
            div.appendChild(del);
        }

        thread.appendChild(div);
    }

    function poll() {
        fetch('messages_fetch.php?id=' + encodeURIComponent(conv)
              + '&after=' + encodeURIComponent(lastId), {
            credentials: 'same-origin'
        })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
            if (!data) return;

            var typingEl = document.getElementById('typingIndicator');
            if (typingEl) typingEl.style.display = data.typing ? 'flex' : 'none';

            if (data.messages && data.messages.length) {
                if (empty) empty.style.display = 'none';
                data.messages.forEach(function (m) {
                    renderMessage(m);
                    var id = parseInt(m.id, 10);
                    if (!isNaN(id) && id > lastId) lastId = id;
                });
                scrollToBottom();
                if (typingEl) typingEl.style.display = 'none';
                var badge = document.querySelector('a[href="messages.php"] .badge');
                if (badge) badge.remove();
            }

            if (typeof data.last_own_id !== 'undefined') {
                updateSeenPill(data.last_own_id, data.last_own_seen);
            }
        })
        .catch(function () { /* silent */ });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = bodyEl.value.trim();
        if (!text) return;

        var csrfToken = form.querySelector('input[name="csrf_token"]').value;
        var convId    = form.querySelector('input[name="conversation_id"]').value;

        btn.disabled = true;

        fetch('message_send.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ conversation_id: convId, body: text, csrf_token: csrfToken })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success && data.message) {
                bodyEl.value = '';

                var csrfToken2 = form.querySelector('input[name="csrf_token"]').value;
                var convId2    = form.querySelector('input[name="conversation_id"]').value;
                fetch('typing_ping.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                    body: new URLSearchParams({ conversation_id: convId2, csrf_token: csrfToken2, clear: '1' })
                }).catch(function () {});

                if (empty) empty.style.display = 'none';
                renderMessage(data.message);
                var id = parseInt(data.message.id, 10);
                if (!isNaN(id) && id > lastId) lastId = id;
                scrollToBottom();

                var stalePill = thread.querySelector('.seen-pill');
                if (stalePill) stalePill.remove();
            } else {
                alert(data.error || 'Something went wrong');
            }
            btn.disabled = false;
        })
        .catch(function (err) {
            console.error('Send error:', err);
            btn.disabled = false;
            if (err instanceof TypeError && /fetch|network/i.test(err.message)) form.submit();
            else alert('Something went wrong sending your message. Check the console.');
        });
    });

    thread.addEventListener('click', function (e) {
        var btn2 = e.target.closest('.bubble-delete');
        if (!btn2) return;
        e.preventDefault();

        var messageId = btn2.dataset.messageId;
        if (!messageId) return;
        if (!confirm('Delete this message?')) return;

        var csrfInput = form.querySelector('input[name="csrf_token"]');
        var csrfToken = csrfInput ? csrfInput.value : '';

        btn2.disabled = true;

        fetch('message_delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ message_id: messageId, csrf_token: csrfToken })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                var bubble = thread.querySelector('.bubble[data-id="' + messageId + '"]');
                if (bubble) {
                    bubble.classList.add('bubble-deleted');
                    var bodyEl2 = bubble.querySelector('.bubble-body');
                    if (bodyEl2) {
                        bodyEl2.innerHTML = '';
                        var em = document.createElement('em');
                        em.className = 'bubble-deleted-text';
                        em.textContent = 'Message deleted';
                        bodyEl2.appendChild(em);
                    }
                    var db = bubble.querySelector('.bubble-delete'); if (db) db.remove();
                    var rb = bubble.querySelector('.bubble-report'); if (rb) rb.remove();
                    var pill = bubble.querySelector('.seen-pill'); if (pill) pill.remove();
                }
            } else {
                alert(data.error || 'Could not delete message.');
                btn2.disabled = false;
            }
        })
        .catch(function (err) {
            console.error('Delete error:', err);
            alert('Could not delete message.');
            btn2.disabled = false;
        });
    });

    var lastPingAt = 0;
    if (bodyEl) {
        bodyEl.addEventListener('input', function () {
            if (bodyEl.value.trim().length === 0) return;
            var now = Date.now();
            if (now - lastPingAt < 1500) return;
            lastPingAt = now;

            var csrfToken = form.querySelector('input[name="csrf_token"]').value;
            var convId    = form.querySelector('input[name="conversation_id"]').value;

            fetch('typing_ping.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams({ conversation_id: convId, csrf_token: csrfToken })
            }).catch(function () {});
        });
    }

    var initialLastOwnId   = card.dataset.lastOwnId ? parseInt(card.dataset.lastOwnId, 10) : 0;
    var initialLastOwnSeen = card.dataset.lastOwnSeen === '1';
    updateSeenPill(initialLastOwnId, initialLastOwnSeen);


    /* ============================================================
       IMAGE ATTACHMENTS
       ============================================================ */
    var imageBtn   = document.getElementById('imageBtn');
    var imageInput = document.getElementById('imageInput');

    if (imageBtn && imageInput) {
        imageBtn.addEventListener('click', function (e) {
            e.preventDefault();
            imageInput.click();
        });

        imageInput.addEventListener('change', function () {
            if (!imageInput.files || !imageInput.files.length) return;
            uploadImageMessage(imageInput.files[0]);
        });
    }

    function uploadImageMessage(file) {
        if (file.size > 5 * 1024 * 1024) {
            alert('Image must be smaller than 5 MB.');
            if (imageInput) imageInput.value = '';
            return;
        }

        var csrfToken = form.querySelector('input[name="csrf_token"]').value;
        var convId    = form.querySelector('input[name="conversation_id"]').value;

        var fd = new FormData();
        fd.append('conversation_id', convId);
        fd.append('csrf_token', csrfToken);
        fd.append('image', file, file.name);

        if (imageBtn) imageBtn.disabled = true;

        fetch('message_image_send.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success && data.message) {
                if (empty) empty.style.display = 'none';
                renderMessage(data.message);
                var id = parseInt(data.message.id, 10);
                if (!isNaN(id) && id > lastId) lastId = id;
                scrollToBottom();
            } else {
                alert(data.error || 'Could not send image.');
            }
            if (imageBtn) imageBtn.disabled = false;
            if (imageInput) imageInput.value = '';
        })
        .catch(function (err) {
            console.error('Image upload error:', err);
            alert('Could not send image.');
            if (imageBtn) imageBtn.disabled = false;
            if (imageInput) imageInput.value = '';
        });
    }


    /* ============================================================
       VOICE RECORDER — race-condition-safe
       ============================================================ */
    var micBtn         = document.getElementById('micBtn');
    var voiceRecorder  = document.getElementById('voiceRecorder');
    var voiceRecTime   = document.getElementById('voiceRecTime');
    var voiceCancelBtn = document.getElementById('voiceCancelBtn');
    var voiceSendBtn   = document.getElementById('voiceSendBtn');

    var mediaRecorder   = null;
    var recordedChunks  = [];
    var recordStartTime = 0;
    var recordTimerId   = null;
    var maxVoiceSeconds = parseInt(form.dataset.voiceMax || '60', 10);
    var isUploading     = false;

    function clearRecorderUI() {
        if (recordTimerId) { clearInterval(recordTimerId); recordTimerId = null; }
        if (voiceRecorder) voiceRecorder.style.display = 'none';
        if (voiceRecTime)  voiceRecTime.textContent = '0:00';
        if (micBtn)        micBtn.disabled = false;
        if (voiceSendBtn)  voiceSendBtn.disabled = false;
    }

    function startRecording() {
        if (isUploading) return;

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert('Voice recording is not supported in this browser.');
            return;
        }

        navigator.mediaDevices.getUserMedia({ audio: true })
            .then(function (stream) {
                recordedChunks = [];
                mediaRecorder = new MediaRecorder(stream);

                mediaRecorder.ondataavailable = function (e) {
                    if (e.data && e.data.size > 0) recordedChunks.push(e.data);
                };

                mediaRecorder.onstop = function () {
                    stream.getTracks().forEach(function (t) { t.stop(); });
                };

                mediaRecorder.start(1000);

                recordStartTime = Date.now();
                if (voiceRecorder) voiceRecorder.style.display = 'flex';
                if (micBtn) micBtn.disabled = true;

                recordTimerId = setInterval(function () {
                    var elapsed = Math.floor((Date.now() - recordStartTime) / 1000);
                    if (voiceRecTime) {
                        voiceRecTime.textContent = Math.floor(elapsed / 60) + ':' + ('0' + (elapsed % 60)).slice(-2);
                    }
                    if (elapsed >= maxVoiceSeconds) {
                        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                            try { mediaRecorder.stop(); } catch (e) {}
                        }
                        if (recordTimerId) { clearInterval(recordTimerId); recordTimerId = null; }
                    }
                }, 250);
            })
            .catch(function (err) {
                console.error('Mic denied:', err);
                alert('Microphone access is needed to record voice messages.');
                clearRecorderUI();
            });
    }

    function cancelRecording() {
        if (recordTimerId) { clearInterval(recordTimerId); recordTimerId = null; }
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            try { mediaRecorder.stop(); } catch (e) {}
        }
        recordedChunks = [];
        mediaRecorder = null;
        clearRecorderUI();
    }

    function sendRecording() {
        if (isUploading) return;

        if (mediaRecorder && mediaRecorder.state === 'recording') {
            var duration = Math.max(1, Math.floor((Date.now() - recordStartTime) / 1000));
            if (recordTimerId) { clearInterval(recordTimerId); recordTimerId = null; }

            var originalOnStop = mediaRecorder.onstop;
            mediaRecorder.onstop = function () {
                if (originalOnStop) originalOnStop.call(mediaRecorder);
                uploadVoiceRecording(duration);
            };

            try {
                mediaRecorder.stop();
            } catch (e) {
                uploadVoiceRecording(duration);
            }
            return;
        }

        var durationFallback = Math.max(1, Math.floor((Date.now() - recordStartTime) / 1000));
        uploadVoiceRecording(durationFallback);
    }

    function uploadVoiceRecording(duration) {
        if (!recordedChunks.length) {
            mediaRecorder = null;
            clearRecorderUI();
            return;
        }

        isUploading = true;
        duration = Math.max(1, duration || 1);

        var mimeType = 'audio/webm';
        try {
            mimeType = (mediaRecorder && mediaRecorder.mimeType) || 'audio/webm';
        } catch (e) { /* ignore */ }

        var blob = new Blob(recordedChunks, { type: mimeType });

        var csrfToken = form.querySelector('input[name="csrf_token"]').value;
        var convId    = form.querySelector('input[name="conversation_id"]').value;

        var fd = new FormData();
        fd.append('conversation_id', convId);
        fd.append('csrf_token', csrfToken);
        fd.append('duration', duration);
        fd.append('voice', blob, 'voice.webm');

        if (voiceSendBtn) voiceSendBtn.disabled = true;

        fetch('message_voice_send.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success && data.message) {
                if (empty) empty.style.display = 'none';
                renderMessage(data.message);
                var id = parseInt(data.message.id, 10);
                if (!isNaN(id) && id > lastId) lastId = id;
                scrollToBottom();
            } else {
                alert(data.error || 'Could not send voice message.');
            }
            recordedChunks = [];
            mediaRecorder = null;
            isUploading = false;
            clearRecorderUI();
        })
        .catch(function (err) {
            console.error('Voice send error:', err);
            alert('Could not send voice message.');
            recordedChunks = [];
            mediaRecorder = null;
            isUploading = false;
            clearRecorderUI();
        });
    }

    if (micBtn) {
        micBtn.addEventListener('click', function (e) { e.preventDefault(); startRecording(); });
    }
    if (voiceCancelBtn) {
        voiceCancelBtn.addEventListener('click', function (e) { e.preventDefault(); cancelRecording(); });
    }
    if (voiceSendBtn) {
        voiceSendBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            sendRecording();
        });
    }

    scrollToBottom();
    setInterval(poll, 4000);
})();


/* ========================================
   BLOCK / UNBLOCK — AJAX
   ======================================== */

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.block-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            handleBlock(form);
        });
    });
});

function handleBlock(form) {
    const button = form.querySelector('button');
    const targetId = form.querySelector('input[name="target_id"]').value;
    const action   = form.querySelector('input[name="block_action"]').value;
    const csrfToken = form.querySelector('input[name="csrf_token"]').value;
    const redirect = form.querySelector('input[name="redirect"]')?.value
                   || (window.location.pathname + window.location.search);

    button.disabled = true;

    fetch('block.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({
            target_id: targetId, block_action: action,
            csrf_token: csrfToken, redirect: redirect
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) window.location.href = redirect;
        else { alert(data.error || 'Something went wrong'); button.disabled = false; }
    })
    .catch(error => {
        console.error('Block error:', error);
        button.disabled = false;
        form.submit();
    });
}