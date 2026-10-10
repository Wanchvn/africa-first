/* ========================================
   QAROTA — AJAX Interactions
   ======================================== */

document.addEventListener('DOMContentLoaded', function() {
    // Like buttons
    document.querySelectorAll('.like-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            handleLike(form);
        });
    });

    // Follow buttons
    document.querySelectorAll('.follow-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            handleFollow(form);
        });
    });

    // Bookmark buttons
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
   Show mini-profile on username hover
   ======================================== */

(function() {
    const cardCache = {};       // { user_id: cardData }
    let hoverTimer = null;
    let hideTimer = null;
    let currentCard = null;
    let currentUserId = null;

    // Create the floating card container once
    function getCardElement() {
        if (currentCard) return currentCard;

        const card = document.createElement('div');
        card.className = 'user-hover-card';
        card.style.display = 'none';
        document.body.appendChild(card);
        currentCard = card;
        return card;
    }

    // Detect hover on any username link
    document.addEventListener('mouseover', function(e) {
        const link = e.target.closest('a[data-user-id]');
        if (!link) return;

        const userId = link.dataset.userId;
        if (!userId) return;

        // If we're already showing this user, do nothing
        if (currentUserId === userId && currentCard && currentCard.style.display === 'block') {
            return;
        }

        clearTimeout(hideTimer);
        clearTimeout(hoverTimer);

        hoverTimer = setTimeout(function() {
            showCard(userId, link);
        }, 300);
    });

    // Detect leaving the username
    document.addEventListener('mouseout', function(e) {
        const link = e.target.closest('a[data-user-id]');
        if (!link) return;

        clearTimeout(hoverTimer);

        // Schedule hide (unless mouse enters the card itself)
        hideTimer = setTimeout(function() {
            hideCard();
        }, 200);
    });

    // Keep the card open when hovering it
    document.addEventListener('mouseover', function(e) {
        if (currentCard && currentCard.contains(e.target)) {
            clearTimeout(hideTimer);
        }
    });

    document.addEventListener('mouseout', function(e) {
        if (currentCard && currentCard.contains(e.target)) {
            const toElement = e.relatedTarget;
            if (!currentCard.contains(toElement)) {
                hideTimer = setTimeout(hideCard, 200);
            }
        }
    });

    function hideCard() {
        if (currentCard) {
            currentCard.style.display = 'none';
        }
        currentUserId = null;
    }

    function showCard(userId, anchorEl) {
        currentUserId = userId;

        // Check cache first
        if (cardCache[userId]) {
            renderCard(cardCache[userId], anchorEl);
            return;
        }

        // Show loading state
        const card = getCardElement();
        card.innerHTML = '<div style="padding: 16px; text-align: center; color: var(--muted);">Loading…</div>';
        positionCard(card, anchorEl);
        card.style.display = 'block';

        // Fetch user data
        fetch('user_card.php?id=' + userId)
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    hideCard();
                    return;
                }
                cardCache[userId] = data.user;
                if (currentUserId === userId) {
                    renderCard(data.user, anchorEl);
                }
            })
            .catch(() => {
                hideCard();
            });
    }

    function renderCard(user, anchorEl) {
        const card = getCardElement();
        const avatarHtml = user.avatar
            ? `<img src="${escapeHtml(user.avatar)}" alt="" class="user-hover-avatar">`
            : `<div class="user-hover-avatar user-hover-avatar-placeholder">${escapeHtml(user.display_name[0].toUpperCase())}</div>`;

        const bioHtml = user.bio
            ? `<p class="user-hover-bio">${escapeHtml(user.bio)}</p>`
            : '';

        const buttonHtml = user.is_self
            ? ''
            : `
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

        // Re-attach follow handler since the card is new HTML
        const form = card.querySelector('.follow-form');
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                handleFollow(form);
                // Update button state inline
                const btn = form.querySelector('button');
                setTimeout(function() {
                    const wasFollowing = btn.classList.contains('btn-secondary');
                    if (wasFollowing) {
                        btn.classList.remove('btn-secondary');
                        btn.classList.add('btn');
                        btn.textContent = 'Follow';
                    } else {
                        btn.classList.add('btn-secondary');
                        btn.classList.remove('btn');
                        btn.textContent = 'Unfollow';
                    }
                    // Update cache
                    if (cardCache[user.id]) {
                        cardCache[user.id].is_following = !wasFollowing;
                    }
                }, 100);
            });
        }
    }

    function positionCard(card, anchorEl) {
        const rect = anchorEl.getBoundingClientRect();
        const cardWidth = 300;

        let left = rect.left + window.scrollX;
        let top = rect.bottom + window.scrollY + 8;

        // Keep within viewport
        if (left + cardWidth > window.scrollX + window.innerWidth - 16) {
            left = window.scrollX + window.innerWidth - cardWidth - 16;
        }
        if (left < window.scrollX + 16) {
            left = window.scrollX + 16;
        }

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
        // Grab the CSRF token from any existing form on the page
        const input = document.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    }
})();


/* ========================================
   COMMENT EDITING
   Inline edit of your own comments
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
    const metaEl = comment.querySelector('.comment-meta-actions');

    if (!bodyEl) return;

    const originalContent = bodyEl.textContent.trim();

    // Replace body with textarea + buttons
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
        if (newContent === '') {
            errorEl.textContent = 'Comment cannot be empty';
            return;
        }
        if (newContent === originalContent) {
            errorEl.textContent = 'No changes made';
            return;
        }

        saveBtn.disabled = true;
        errorEl.textContent = '';

        // Find CSRF token from any form on the page
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        const csrfToken = csrfInput ? csrfInput.value : '';

        fetch('edit_comment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                comment_id: commentId,
                content: newContent,
                csrf_token: csrfToken
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Replace body with the new content
                bodyEl.innerHTML = escapeHtmlJs(data.content);

                // Add or update the "Edited" label in the meta
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
        .catch(function() {
            errorEl.textContent = 'Network error';
            saveBtn.disabled = false;
        });
    });

    cancelBtn.addEventListener('click', function() {
        bodyEl.innerHTML = escapeHtmlJs(originalContent);
    });
}

function escapeHtmlJs(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/* ========================================
   MESSAGES — AJAX send + poll
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

    var lastId = 0;
    thread.querySelectorAll('.bubble').forEach(function (el) {
        var id = parseInt(el.dataset.id, 10);
        if (!isNaN(id) && id > lastId) lastId = id;
    });

    function scrollToBottom() {
        thread.scrollTop = thread.scrollHeight;
    }

 function renderMessage(m) {
    var mine = (parseInt(m.sender_id, 10) === me);
    var isDeleted = !!m.deleted_at;

    var div = document.createElement('div');
    div.className = 'bubble ' + (mine ? 'bubble-mine' : 'bubble-theirs')
                  + (isDeleted ? ' bubble-deleted' : '');
    if (m.id) div.dataset.id = m.id;

    // Body
    var bodyDiv = document.createElement('div');
    bodyDiv.className = 'bubble-body';
    if (isDeleted) {
        var em = document.createElement('em');
        em.className = 'bubble-deleted-text';
        em.textContent = 'Message deleted';
        bodyDiv.appendChild(em);
    } else {
        var lines = String(m.body).split('\n');
        lines.forEach(function (line, i) {
            if (i > 0) bodyDiv.appendChild(document.createElement('br'));
            bodyDiv.appendChild(document.createTextNode(line));
        });
    }

    // Time
    var timeDiv = document.createElement('div');
    timeDiv.className = 'bubble-time';
    timeDiv.textContent = m.created_at;

    div.appendChild(bodyDiv);
    div.appendChild(timeDiv);

    // Report link (incoming + not deleted)
    if (!mine && !isDeleted) {
        var rep = document.createElement('a');
        rep.className = 'bubble-report';
        rep.href = 'report_message.php?conversation_id=' + encodeURIComponent(conv)
                 + '&message_id=' + encodeURIComponent(m.id);
        rep.title = 'Report';
        rep.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>';
        div.appendChild(rep);
    }

    // Delete button (outgoing + not deleted)
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
            if (!data || !data.messages || !data.messages.length) return;
            if (empty) empty.style.display = 'none';
            data.messages.forEach(function (m) {
                renderMessage(m);
                var id = parseInt(m.id, 10);
                if (!isNaN(id) && id > lastId) lastId = id;
            });
            scrollToBottom();

            var badge = document.querySelector('a[href="messages.php"] .badge');
            if (badge) badge.remove();
        })
        .catch(function () {});
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
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                conversation_id: convId,
                body: text,
                csrf_token: csrfToken
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success && data.message) {
                bodyEl.value = '';
                if (empty) empty.style.display = 'none';
                renderMessage(data.message);
                var id = parseInt(data.message.id, 10);
                if (!isNaN(id) && id > lastId) lastId = id;
                scrollToBottom();
            } else {
                alert(data.error || 'Something went wrong');
            }
            btn.disabled = false;
        })
        .catch(function (err) {
    console.error('Send error:', err);
    btn.disabled = false;
    // Only fall back if the network request truly failed.
    // If a downstream render threw, don't hijack the page.
    if (err instanceof TypeError && /fetch|network/i.test(err.message)) {
        form.submit();
    } else {
        alert('Something went wrong sending your message. Check the console.');
    }
});
    });



    // ---- Delete message handler (event delegation) ----
thread.addEventListener('click', function (e) {
    var btn = e.target.closest('.bubble-delete');
    if (!btn) return;
    e.preventDefault();

    var messageId = btn.dataset.messageId;
    if (!messageId) return;
    if (!confirm('Delete this message?')) return;

    var csrfInput = form.querySelector('input[name="csrf_token"]');
    var csrfToken = csrfInput ? csrfInput.value : '';

    btn.disabled = true;

    fetch('message_delete.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            message_id: messageId,
            csrf_token: csrfToken
        })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.success) {
            var bubble = thread.querySelector('.bubble[data-id="' + messageId + '"]');
            if (bubble) {
                bubble.classList.add('bubble-deleted');
                var bodyEl = bubble.querySelector('.bubble-body');
                if (bodyEl) {
                    bodyEl.innerHTML = '';
                    var em = document.createElement('em');
                    em.className = 'bubble-deleted-text';
                    em.textContent = 'Message deleted';
                    bodyEl.appendChild(em);
                }
                // Remove delete button + report link
                var db = bubble.querySelector('.bubble-delete');
                if (db) db.remove();
                var rb = bubble.querySelector('.bubble-report');
                if (rb) rb.remove();
            }
        } else {
            alert(data.error || 'Could not delete message.');
            btn.disabled = false;
        }
    })
    .catch(function (err) {
        console.error('Delete error:', err);
        alert('Could not delete message.');
        btn.disabled = false;
    });
});

    scrollToBottom();
    setInterval(poll, 4000);
})();




/* ========================================
   BLOCK / UNBLOCK — AJAX
   ======================================== */

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.block-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            // If the form has a confirm() attribute, let the inline handler run first
            var btn = form.querySelector('button[type="submit"]');
            if (btn && btn.getAttribute('onclick')) {
                // The onclick already ran; if it returned false, we shouldn't submit
                // (inline onclick returning false stops submit before this handler fires anyway)
            }
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
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            target_id: targetId,
            block_action: action,
            csrf_token: csrfToken,
            redirect: redirect
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Simplest UX: reload so all state (buttons, banners) reflects the new block status
            window.location.href = redirect;
        } else {
            alert(data.error || 'Something went wrong');
            button.disabled = false;
        }
    })
    .catch(error => {
        console.error('Block error:', error);
        button.disabled = false;
        form.submit();
    });
}