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

    // NEW: wire up comment forms
    document.querySelectorAll('.comment-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            handleComment(form);
        });
    });
});

// Toggle the group info panel
(function () {
    var btn = document.getElementById('groupInfoBtn');
    var panel = document.getElementById('groupPanel');
    if (!btn || !panel) return;
    btn.addEventListener('click', function (e) {
        e.preventDefault();
        panel.hidden = !panel.hidden;
        btn.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
        if (typeof lucide !== 'undefined') lucide.createIcons();
    });
})();

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

/* ---- COMMENT HANDLER ---- */
function handleComment(form) {
    const button = form.querySelector('button');
    const postId = form.querySelector('input[name="post_id"]').value;
    const contentInput = form.querySelector('input[name="content"]');
    const content = contentInput.value.trim();

    if (!content) return;

    const csrfInput = form.querySelector('input[name="csrf_token"]');
    const csrfToken = csrfInput ? csrfInput.value : '';

    button.disabled = true;

    fetch('interact.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            action: 'comment',
            post_id: postId,
            content: content,
            csrf_token: csrfToken
        })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success || !data.comment) {
            alert(data.error || 'Something went wrong');
            button.disabled = false;
            return;
        }

        const c = data.comment;
        contentInput.value = '';

        // Append the new comment inline (no page reload)
        const card = form.closest('.card');
        if (!card) { button.disabled = false; return; }

        let comments = card.querySelector('.comments');
        if (!comments) {
            comments = document.createElement('div');
            comments.className = 'comments';
            form.parentNode.insertBefore(comments, form);
        }

        const comment = document.createElement('div');
        comment.className = 'comment';

        const header = document.createElement('div');
        header.className = 'comment-header';

        if (c.avatar) {
            const img = document.createElement('img');
            img.className = 'avatar avatar-tiny';
            img.src = c.avatar;
            img.alt = '';
            header.appendChild(img);
        } else {
            const av = document.createElement('div');
            av.className = 'avatar avatar-tiny avatar-placeholder';
            av.textContent = (c.display_name || c.username || '?').charAt(0).toUpperCase();
            header.appendChild(av);
        }

        const nameLink = document.createElement('a');
        nameLink.href = 'profile.php?u=' + encodeURIComponent(c.username);
        nameLink.dataset.userId = c.user_id;
        const strong = document.createElement('strong');
        strong.textContent = c.display_name || c.username;
        nameLink.appendChild(strong);
        header.appendChild(nameLink);

        const body = document.createElement('div');
        body.className = 'comment-body';
        body.textContent = c.content;

        const meta = document.createElement('div');
        meta.className = 'meta comment-meta';
        meta.style.cssText = 'display:flex; justify-content:space-between; align-items:center;';
        const timeSpan = document.createElement('span');
        timeSpan.textContent = c.created_at;
        meta.appendChild(timeSpan);

        const editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'comment-edit-btn';
        editBtn.dataset.commentId = c.id;
        editBtn.style.cssText = 'background:none;border:none;color:var(--muted);font-size:0.75rem;cursor:pointer;padding:0;text-decoration:underline;';
        editBtn.textContent = 'Edit';
        editBtn.addEventListener('click', function(e) {
            e.preventDefault();
            startEditComment(editBtn);
        });
        meta.appendChild(editBtn);

        comment.appendChild(header);
        comment.appendChild(body);
        comment.appendChild(meta);
        comments.appendChild(comment);

        // Bump comment count in the actions row
        const countEl = card.querySelector('.comment-count');
        if (countEl) {
            const countText = Array.from(countEl.childNodes).reverse().find(function(node) {
                return node.nodeType === Node.TEXT_NODE && /\d+/.test(node.textContent);
            });
            if (countText) {
                const current = parseInt(countText.textContent.match(/\d+/)[0], 10);
                countText.textContent = countText.textContent.replace(/\d+/, current + 1);
            }
        }

        button.disabled = false;
    })
    .catch(err => {
        console.error('Comment error:', err);
        button.disabled = false;
        alert('Unable to send your comment. Please try again.');
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
   MESSAGES — AJAX send + poll + typing + read receipts + voice + images + video
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
    var reactAddLabel = card.dataset.reactLabel || 'Add reaction';

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

    function renderReactionChips(container, messageId, reactions) {
        while (container.firstChild) container.removeChild(container.firstChild);
        (reactions || []).forEach(function (r) {
            var chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'reaction-chip' + (r.mine ? ' reaction-mine' : '');
            chip.dataset.messageId = messageId;
            chip.dataset.emoji = r.emoji;

            var emoji = document.createElement('span');
            emoji.className = 'reaction-emoji';
            emoji.textContent = r.emoji;
            var count = document.createElement('span');
            count.className = 'reaction-count';
            count.textContent = r.count;

            chip.appendChild(emoji);
            chip.appendChild(count);
            container.appendChild(chip);
        });
    }

    function applyReactionUpdates(updates) {
        (updates || []).forEach(function (state) {
            var messageId = parseInt(state.message_id, 10);
            if (isNaN(messageId)) return;

            var bubble = thread.querySelector('.bubble[data-id="' + messageId + '"]');
            if (!bubble || bubble.classList.contains('bubble-deleted')) return;

            var container = bubble.querySelector('.bubble-reactions');
            if (container) {
                renderReactionChips(container, messageId, state.reactions);
            }
        });
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
        var isVideo = (m.message_type === 'video') && m.video_path;

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
            src.src = 'voice_serve.php?id=' + encodeURIComponent(m.id);
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
            link.href = 'image_serve.php?id=' + encodeURIComponent(m.id);
            link.target = '_blank';
            link.rel = 'noopener';

            var img = document.createElement('img');
            img.className = 'dm-image';
            img.src = 'image_serve.php?id=' + encodeURIComponent(m.id);
            img.alt = 'Image';
            img.loading = 'lazy';

            link.appendChild(img);
            bodyDiv.appendChild(link);
        } else if (isVideo) {
            var vwrap = document.createElement('div');
            vwrap.className = 'video-message';

            var vid = document.createElement('video');
            vid.controls = true;
            vid.preload = 'metadata';
            vid.className = 'dm-video';
            if (m.video_thumb) vid.poster = 'video_thumb_serve.php?id=' + encodeURIComponent(m.id);

            var vsrc = document.createElement('source');
            vsrc.src = 'video_serve.php?id=' + encodeURIComponent(m.id);
            vid.appendChild(vsrc);
            vwrap.appendChild(vid);

            var vdur = parseInt(m.video_duration, 10) || 0;
            if (vdur > 0) {
                var vdurEl = document.createElement('div');
                vdurEl.className = 'video-duration';
                vdurEl.textContent = Math.floor(vdur / 60) + ':' + ('0' + (vdur % 60)).slice(-2);
                vwrap.appendChild(vdurEl);
            }

            bodyDiv.appendChild(vwrap);
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

        if (!isDeleted) {
            var reactions = document.createElement('div');
            reactions.className = 'bubble-reactions';
            reactions.dataset.messageId = m.id;
            renderReactionChips(reactions, m.id, m.reactions);
            div.appendChild(reactions);

            var trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'bubble-react-trigger';
            trigger.dataset.messageId = m.id;
            trigger.title = reactAddLabel;
            trigger.setAttribute('aria-label', reactAddLabel);
            trigger.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11v1a10 10 0 1 1-9-10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/><line x1="16" y1="5" x2="22" y2="5"/><line x1="19" y1="2" x2="19" y2="8"/></svg>';
            div.appendChild(trigger);

            var picker = document.createElement('div');
            picker.className = 'reaction-picker';
            picker.dataset.messageId = m.id;
            picker.hidden = true;
            ['👍', '❤️', '😂', '😮', '😢', '🙏'].forEach(function (emoji) {
                var pickerButton = document.createElement('button');
                pickerButton.type = 'button';
                pickerButton.className = 'reaction-picker-btn';
                pickerButton.dataset.messageId = m.id;
                pickerButton.dataset.emoji = emoji;
                pickerButton.textContent = emoji;
                picker.appendChild(pickerButton);
            });
            div.appendChild(picker);
        }

        if (!mine && !isDeleted) {
            var rep = document.createElement('a');
            rep.className = 'bubble-report';
            rep.href = 'report_message.php?conversation_id=' + encodeURIComponent(conv)
                     + '&message_id=' + encodeURIComponent(m.id);
            rep.title = card.dataset.reportLabel || 'Report';
            rep.setAttribute('aria-label', rep.title);
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
            if (typingEl) {
                var tu = data.typing_users || [];
                if (tu.length === 0) {
                    typingEl.style.display = 'none';
                } else {
                    var names = tu.map(function (u) { return u.display_name || u.username; });
                    var text = '';
                    if (names.length === 1) {
                        text = names[0] + ' is typing…';
                    } else if (names.length === 2) {
                        text = names[0] + ' and ' + names[1] + ' are typing…';
                    } else {
                        text = names[0] + ', ' + names[1] + ' and ' + (names.length - 2) + ' others are typing…';
                    }
                    var nameEl = typingEl.querySelector('.typing-name');
                    if (nameEl) nameEl.textContent = text;
                    var textEl = typingEl.querySelector('.typing-text');
                    if (textEl) textEl.textContent = '';
                    typingEl.style.display = 'flex';
                }
            }

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

            applyReactionUpdates(data.reaction_states);

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
                    var reactions = bubble.querySelector('.bubble-reactions'); if (reactions) reactions.remove();
                    var reactTrigger = bubble.querySelector('.bubble-react-trigger'); if (reactTrigger) reactTrigger.remove();
                    var reactionPicker = bubble.querySelector('.reaction-picker'); if (reactionPicker) reactionPicker.remove();
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

    thread.addEventListener('click', function (e) {
        var trigger = e.target.closest('.bubble-react-trigger');
        if (trigger) {
            e.preventDefault();
            var messageId = trigger.dataset.messageId;
            thread.querySelectorAll('.reaction-picker').forEach(function (picker) {
                if (picker.dataset.messageId !== messageId) picker.hidden = true;
            });
            var picker = thread.querySelector(
                '.reaction-picker[data-message-id="' + messageId + '"]'
            );
            if (picker) picker.hidden = !picker.hidden;
            return;
        }

        var pickerButton = e.target.closest('.reaction-picker-btn');
        if (pickerButton) {
            e.preventDefault();
            sendReaction(pickerButton.dataset.messageId, pickerButton.dataset.emoji);
            return;
        }

        var chip = e.target.closest('.reaction-chip');
        if (chip) {
            e.preventDefault();
            sendReaction(chip.dataset.messageId, chip.dataset.emoji);
        }
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.reaction-picker') && !e.target.closest('.bubble-react-trigger')) {
            thread.querySelectorAll('.reaction-picker').forEach(function (picker) {
                picker.hidden = true;
            });
        }
    });

    function sendReaction(messageId, emoji) {
        var csrfInput = form.querySelector('input[name="csrf_token"]');
        var csrfToken = csrfInput ? csrfInput.value : '';

        fetch('message_react.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                message_id: messageId,
                emoji: emoji,
                csrf_token: csrfToken
            })
        })
        .then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok || !data.success) {
                    throw new Error(data.error || 'Could not update reaction.');
                }
                return data;
            });
        })
        .then(function (data) {
            var bubble = thread.querySelector('.bubble[data-id="' + messageId + '"]');
            if (!bubble) return;

            var container = bubble.querySelector('.bubble-reactions');
            if (container) {
                renderReactionChips(container, messageId, data.reactions);
            }
            var picker = bubble.querySelector('.reaction-picker');
            if (picker) picker.hidden = true;
        })
        .catch(function (error) {
            console.error('Reaction error:', error);
            alert(error.message || 'Could not update reaction.');
        });
    }

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
       VIDEO ATTACHMENTS
       ============================================================ */
    var videoBtn   = document.getElementById('videoBtn');
    var videoInput = document.getElementById('videoInput');

    if (videoBtn && videoInput) {
        videoBtn.addEventListener('click', function (e) {
            e.preventDefault();
            videoInput.click();
        });

        videoInput.addEventListener('change', function () {
            if (!videoInput.files || !videoInput.files.length) return;
            uploadVideoMessage(videoInput.files[0]);
        });
    }

    function uploadVideoMessage(file) {
        if (file.size > 25 * 1024 * 1024) {
            alert('Video must be smaller than 25 MB.');
            if (videoInput) videoInput.value = '';
            return;
        }

        var csrfToken = form.querySelector('input[name="csrf_token"]').value;
        var convId    = form.querySelector('input[name="conversation_id"]').value;

        var probe = document.createElement('video');
        probe.preload = 'metadata';
        probe.onloadedmetadata = function () {
            window.URL.revokeObjectURL(probe.src);
            doVideoUpload(file, csrfToken, convId, Math.round(probe.duration) || 0);
        };
        probe.onerror = function () {
            doVideoUpload(file, csrfToken, convId, 0);
        };
        probe.src = URL.createObjectURL(file);
    }

    function doVideoUpload(file, csrfToken, convId, duration) {
        var fd = new FormData();
        fd.append('conversation_id', convId);
        fd.append('csrf_token', csrfToken);
        fd.append('duration', duration);
        fd.append('video', file, file.name);

        if (videoBtn) videoBtn.disabled = true;

        fetch('message_video_send.php', {
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
                alert(data.error || 'Could not send video.');
            }
            if (videoBtn) videoBtn.disabled = false;
            if (videoInput) videoInput.value = '';
        })
        .catch(function (err) {
            console.error('Video upload error:', err);
            alert('Could not send video.');
            if (videoBtn) videoBtn.disabled = false;
            if (videoInput) videoInput.value = '';
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


/* ========================================
   HEADER — mobile nav toggle + profile menu
   ======================================== */

(function () {
    'use strict';

    window.toggleNav = function () {
        var nav = document.getElementById('navLinks');
        var overlay = document.getElementById('navOverlay');
        if (!nav || !overlay) return;
        var isOpen = nav.classList.toggle('open');
        overlay.classList.toggle('open', isOpen);
    };

    document.addEventListener('DOMContentLoaded', function () {

        var navLinks = document.getElementById('navLinks');
        if (navLinks) {
            navLinks.querySelectorAll('a').forEach(function (link) {
                link.addEventListener('click', function () {
                    navLinks.classList.remove('open');
                    var ov = document.getElementById('navOverlay');
                    if (ov) ov.classList.remove('open');
                });
            });
        }

        var btn         = document.getElementById('profileMenuBtn');
        var dropdown    = document.getElementById('profileMenuDropdown');
        var settingsBtn = document.getElementById('settingsToggle');
        var settingsSub = document.getElementById('settingsSubmenu');

        if (!btn || !dropdown) return;

        function closeAll() {
            dropdown.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
            if (settingsSub) settingsSub.hidden = true;
            if (settingsBtn) settingsBtn.setAttribute('aria-expanded', 'false');
        }

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = !dropdown.hidden;
            if (isOpen) {
                closeAll();
            } else {
                dropdown.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }
        });

        if (settingsBtn && settingsSub) {
            settingsBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var isOpen = !settingsSub.hidden;
                settingsSub.hidden = isOpen;
                settingsBtn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            });
        }

        document.addEventListener('click', function (e) {
            if (!dropdown.contains(e.target) && !btn.contains(e.target)) {
                closeAll();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeAll();
        });
    });
})();


/* ========================================
   NAV BADGE POLLING — notifications + messages
   ======================================== */

(function () {
    var notifBadge = document.getElementById('notifBadge');
    var msgBadge   = document.getElementById('msgBadge');

    if (!notifBadge && !msgBadge) return;

    var notifCount = notifBadge && !notifBadge.hidden
        ? parseInt(notifBadge.textContent, 10) || 0
        : 0;

    function renderBadge(el, count) {
        if (!el) return;
        if (count > 0) {
            el.textContent = count;
            el.hidden = false;
        } else {
            el.hidden = true;
        }
    }

    function pollNotifications() {
        if (!notifBadge) return;
        fetch('notifications_count.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.success) return;
                if (data.count !== notifCount) {
                    renderBadge(notifBadge, data.count);
                    notifCount = data.count;

                    if (data.count > 0) {
                        notifBadge.animate(
                            [
                                { transform: 'scale(1)' },
                                { transform: 'scale(1.25)' },
                                { transform: 'scale(1)' }
                            ],
                            { duration: 400, easing: 'ease-in-out' }
                        );
                    }
                }
            })
            .catch(function () { /* silent */ });
    }

    function pollMessages() {
        if (!msgBadge) return;
        fetch('messages_count.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.success) return;
                renderBadge(msgBadge, data.count);
            })
            .catch(function () { /* silent */ });
    }

    pollNotifications();
    pollMessages();

    setInterval(pollNotifications, 15000);
    setInterval(pollMessages, 15000);

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            pollNotifications();
            pollMessages();
        }
    });
})();


/* ============================================================
   POST VOICE RECORDER
   Mirrors the DM recorder pattern. Targets #compose-form.
   ============================================================ */

(function () {
    var form = document.getElementById('compose-form');
    if (!form) return;

    var micBtn         = document.getElementById('postMicBtn');
    var voiceRecorder  = document.getElementById('postVoiceRecorder');
    var voiceRecTime   = document.getElementById('postVoiceRecTime');
    var voiceCancelBtn = document.getElementById('postVoiceCancelBtn');
    var voiceSendBtn   = document.getElementById('postVoiceSendBtn');
    var statusEl       = document.getElementById('postRecStatus');

    var hiddenVoiceInput = document.getElementById('postVoiceFile');
    var durationInput    = document.getElementById('postVoiceDuration');
    var previewWrap      = document.getElementById('postVoicePreview');
    var previewAudio     = document.getElementById('postVoiceAudio');
    var discardBtn       = document.getElementById('postVoiceDiscard');

    if (!micBtn || !hiddenVoiceInput) return;

    var mediaRecorder   = null;
    var recordedChunks  = [];
    var recordStartTime = 0;
    var recordTimerId   = null;
    var maxVoiceSeconds = parseInt(form.dataset.voiceMax || '180', 10);
    var isUploading     = false;

    function clearRecorderUI() {
        if (recordTimerId) { clearInterval(recordTimerId); recordTimerId = null; }
        if (voiceRecorder) voiceRecorder.style.display = 'none';
        if (voiceRecTime)  voiceRecTime.textContent = '0:00';
        if (statusEl)      statusEl.textContent = '';
        if (micBtn)        micBtn.disabled = false;
        if (voiceSendBtn)  voiceSendBtn.disabled = false;
    }

    function pickMime() {
        var candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'];
        for (var i = 0; i < candidates.length; i++) {
            if (window.MediaRecorder && MediaRecorder.isTypeSupported(candidates[i])) {
                return candidates[i];
            }
        }
        return '';
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
                var mime = pickMime();
                mediaRecorder = mime
                    ? new MediaRecorder(stream, { mimeType: mime })
                    : new MediaRecorder(stream);

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
                        voiceRecTime.textContent =
                            Math.floor(elapsed / 60) + ':' + ('0' + (elapsed % 60)).slice(-2);
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
                alert('Microphone access is needed to record voice notes.');
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

    function finishRecording() {
        if (isUploading) return;

        if (mediaRecorder && mediaRecorder.state === 'recording') {
            var duration = Math.max(1, Math.floor((Date.now() - recordStartTime) / 1000));
            if (recordTimerId) { clearInterval(recordTimerId); recordTimerId = null; }

            var originalOnStop = mediaRecorder.onstop;
            mediaRecorder.onstop = function () {
                if (originalOnStop) originalOnStop.call(mediaRecorder);
                stageVoicePreview(duration);
            };

            try {
                mediaRecorder.stop();
            } catch (e) {
                stageVoicePreview(duration);
            }
            return;
        }

        var fallback = Math.max(1, Math.floor((Date.now() - recordStartTime) / 1000));
        stageVoicePreview(fallback);
    }

function stageVoicePreview(duration) {
    if (!recordedChunks.length) {
        mediaRecorder = null;
        clearRecorderUI();
        return;
    }

    duration = Math.max(1, duration || 1);

    var mimeType = 'audio/webm';
    try {
        mimeType = (mediaRecorder && mediaRecorder.mimeType) || 'audio/webm';
    } catch (e) {}

    var blob = new Blob(recordedChunks, { type: mimeType });
    var ext = mimeType.indexOf('mp4') !== -1 ? 'm4a'
            : mimeType.indexOf('ogg') !== -1 ? 'ogg'
            : 'webm';

    var file = new File([blob], 'voice.' + ext, { type: blob.type });
    var dt = new DataTransfer();
    dt.items.add(file);
    hiddenVoiceInput.files = dt.files;

    if (durationInput) durationInput.value = duration;

  /*  if (previewAudio) {
        var objectUrl = URL.createObjectURL(blob);
        previewAudio.src = objectUrl;

        // Force-load metadata so the native player shows the real duration
        previewAudio.load();

        // When metadata loads, if the browser reports a duration, trust it
        // (some browsers give fractional times — round up)
        previewAudio.addEventListener('loadedmetadata', function onMeta() {
            previewAudio.removeEventListener('loadedmetadata', onMeta);
            var realDur = Math.max(1, Math.round(previewAudio.duration || duration));
            if (durationInput) durationInput.value = realDur;
        });
    } */
    if (previewWrap) previewWrap.hidden = false;

    recordedChunks = [];
    mediaRecorder = null;
    clearRecorderUI();
}

    function discardPreview() {
        hiddenVoiceInput.value = '';
        if (durationInput) durationInput.value = 0;
        if (previewAudio) previewAudio.src = '';
        if (previewWrap) previewWrap.hidden = true;
    }

    micBtn.addEventListener('click', function (e) { e.preventDefault(); startRecording(); });
    if (voiceCancelBtn) voiceCancelBtn.addEventListener('click', function (e) { e.preventDefault(); cancelRecording(); });
    if (voiceSendBtn)   voiceSendBtn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); finishRecording(); });
    if (discardBtn)     discardBtn.addEventListener('click', function (e) { e.preventDefault(); discardPreview(); });

    // Prevent submitting while a recording is in progress
    form.addEventListener('submit', function (e) {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            e.preventDefault();
            alert('Please stop or cancel the recording first.');
        }
    });
})();



/* ============================================================
   POST VOICE PLAYER — play/pause + seekable progress
   FIXED: auto-resume edge case, ended progress reset, scrub flicker
   ============================================================ */

(function () {
    function attachVoicePlayer(wrapper) {
        var audio = wrapper.querySelector('audio');
        var btn   = wrapper.querySelector('.post-voice-play');
        var wave  = wrapper.querySelector('.post-voice-wave');
        var time  = wrapper.querySelector('.post-voice-time');
        if (!audio || !btn || !wave) return;

        var tooltip = document.createElement('span');
        tooltip.className = 'post-voice-seek-tooltip';
        tooltip.setAttribute('aria-hidden', 'true');
        wave.appendChild(tooltip);
        wave.setAttribute('role', 'slider');
        wave.setAttribute('tabindex', '0');
        wave.setAttribute('aria-label', 'Seek voice note');
        wave.setAttribute('aria-valuemin', '0');
        wave.setAttribute('aria-valuemax', '100');
        wave.setAttribute('aria-valuenow', '0');

        var dragging = false;
        var resumeAfterDrag = false;

        function fmt(s) {
            s = Math.max(0, Math.floor(s || 0));
            return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
        }

        function duration() {
            var length = audio.duration || parseFloat(wrapper.dataset.duration) || 0;
            return Number.isFinite(length) ? length : 0;
        }

        function updateProgress() {
            var length = duration();
            var pct = length > 0 ? Math.max(0, Math.min(100, (audio.currentTime / length) * 100)) : 0;
            wave.style.setProperty('--progress', pct + '%');
            wave.setAttribute('aria-valuenow', String(Math.round(pct)));
            if (time) time.textContent = fmt(audio.currentTime);
        }

        function seekFromClientX(clientX) {
            var bounds = wave.getBoundingClientRect();
            var length = duration();
            if (!length || bounds.width <= 0) return;
            var fraction = Math.max(0, Math.min(1, (clientX - bounds.left) / bounds.width));
            audio.currentTime = fraction * length;
            updateProgress();
        }

        function setTooltip(clientX) {
            var bounds = wave.getBoundingClientRect();
            var length = duration();
            if (bounds.width <= 0 || !length) return;
            var fraction = Math.max(0, Math.min(1, (clientX - bounds.left) / bounds.width));
            tooltip.textContent = fmt(fraction * length);
            tooltip.style.left = (fraction * 100) + '%';
        }

        function play() {
            document.querySelectorAll('.post-voice audio').forEach(function (otherAudio) {
                if (otherAudio !== audio) otherAudio.pause();
            });
            audio.play();
        }

        btn.addEventListener('click', function () {
            if (audio.paused) {
                play();
            } else {
                audio.pause();
            }
        });

        audio.addEventListener('play', function () {
            btn.classList.add('playing');
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>';
        });

        audio.addEventListener('pause', function () {
            // FIX: don't swap icon mid-scrub (prevents flicker while dragging)
            if (dragging) return;
            btn.classList.remove('playing');
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><polygon points="6 4 20 12 6 20 6 4"/></svg>';
        });

        audio.addEventListener('ended', function () {
            btn.classList.remove('playing');
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><polygon points="6 4 20 12 6 20 6 4"/></svg>';
            // FIX: keep the wave/time consistent at the end
            updateProgress();
        });

        audio.addEventListener('timeupdate', function () {
            if (!dragging) updateProgress();
        });

        audio.addEventListener('loadedmetadata', function () {
            updateProgress();
        });

        wave.addEventListener('pointerdown', function (event) {
            if (!duration()) return;
            event.preventDefault();
            dragging = true;
            resumeAfterDrag = !audio.paused;
            if (resumeAfterDrag) audio.pause();
            wave.setPointerCapture(event.pointerId);
            seekFromClientX(event.clientX);
            setTooltip(event.clientX);
            tooltip.classList.add('visible');
        });

        wave.addEventListener('pointermove', function (event) {
            setTooltip(event.clientX);
            if (dragging) seekFromClientX(event.clientX);
        });

        function finishScrub(event) {
            if (!dragging) return;
            if (typeof event.clientX === 'number') {
                seekFromClientX(event.clientX);
                setTooltip(event.clientX); // FIX: keep tooltip aligned after release
            }
            dragging = false;

            // FIX: don't auto-resume if the drag landed at the very end
            var length = duration();
            var atEnd = length > 0 && audio.currentTime >= length - 0.05;
            if (resumeAfterDrag && !atEnd) play();
            resumeAfterDrag = false;
        }

        wave.addEventListener('pointerup', finishScrub);
        wave.addEventListener('pointercancel', finishScrub);

        wave.addEventListener('pointerenter', function (event) {
            setTooltip(event.clientX);
            tooltip.classList.add('visible');
        });

        wave.addEventListener('pointerleave', function () {
            if (!dragging) tooltip.classList.remove('visible');
        });

        wave.addEventListener('keydown', function (event) {
            var length = duration();
            if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                event.preventDefault();
                if (!length) return;
                audio.currentTime = Math.max(0, Math.min(length, audio.currentTime + (event.key === 'ArrowRight' ? 5 : -5)));
                updateProgress();
            } else if (event.key === 'Home' || event.key === 'End') {
                event.preventDefault();
                if (!length) return;
                audio.currentTime = event.key === 'Home' ? 0 : length;
                updateProgress();
            } else if (event.key === ' ' || event.key === 'Enter') {
                event.preventDefault();
                if (audio.paused) play();
                else audio.pause();
            }
        });

        updateProgress();
    }

    document.querySelectorAll('.post-voice').forEach(attachVoicePlayer);
})();