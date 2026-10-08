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