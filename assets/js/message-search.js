/* ============================================================
   MESSAGE SEARCH — AJAX live search across DM history
   ============================================================ */
(function () {
    var input     = document.getElementById('messageSearch');
    var clearBtn  = document.getElementById('messageSearchClear');
    var results   = document.getElementById('messageSearchResults');
    if (!input || !results) return;

    var debounceTimer = null;
    var lastQuery     = '';
    var inFlight      = null;

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function highlight(text, needle) {
        if (!needle) return escapeHtml(text);
        var safe = escapeHtml(text);
        var safeNeedle = escapeHtml(needle);
        var re = new RegExp(
            safeNeedle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'),
            'gi'
        );
        return safe.replace(re, function (match) {
            return '<mark class="search-highlight">' + match + '</mark>';
        });
    }

    function showResults(data) {
        results.hidden = false;
        results.innerHTML = '';

        if (!data.results || data.results.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'message-search-empty';
            empty.textContent = 'No messages matched "' + data.query + '".';
            results.appendChild(empty);
            return;
        }

        var header = document.createElement('div');
        header.className = 'message-search-header';
        header.textContent = data.count +
            (data.count === 1 ? ' result for ' : ' results for ') +
            '"' + data.query + '"';
        results.appendChild(header);

        data.results.forEach(function (r) {
            var link = document.createElement('a');
            link.className = 'message-search-result';
            link.href = 'conversation.php?id=' + encodeURIComponent(r.conversation_id)
                      + '#message-' + encodeURIComponent(r.message_id);

            var meta = document.createElement('div');
            meta.className = 'message-search-result-meta';
            meta.innerHTML =
                '<strong>' + escapeHtml(r.conversation) + '</strong>' +
                ' · ' + escapeHtml(r.sender_name) +
                ' · ' + escapeHtml(r.created_at);

            var snip = document.createElement('div');
            snip.className = 'message-search-result-snippet';
            snip.innerHTML = highlight(r.snippet, data.query);

            link.appendChild(meta);
            link.appendChild(snip);
            results.appendChild(link);
        });
    }

    function runSearch(q) {
        if (q.length < 2) {
            results.hidden = true;
            results.innerHTML = '';
            return;
        }

        if (q === lastQuery) return;
        lastQuery = q;

        if (inFlight) {
            try { inFlight.abort(); } catch (e) {}
        }
        var controller = new AbortController();
        inFlight = controller;

        fetch('message_search.php?q=' + encodeURIComponent(q), {
            credentials: 'same-origin',
            signal: controller.signal
        })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
            if (!data || !data.success) return;
            if (data.query !== lastQuery) return;
            showResults(data);
        })
        .catch(function (err) {
            if (err.name !== 'AbortError') {
                console.error('message search error:', err);
            }
        });
    }

    input.addEventListener('input', function () {
        var q = input.value.trim();
        clearBtn.hidden = q.length === 0;

        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
            runSearch(q);
        }, 220);
    });

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            input.value = '';
            clearBtn.hidden = true;
            results.hidden = true;
            results.innerHTML = '';
            lastQuery = '';
            input.focus();
        });
    }

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            input.value = '';
            clearBtn.hidden = true;
            results.hidden = true;
            results.innerHTML = '';
            lastQuery = '';
        }
    });

    // Re-init lucide icons for the clear button
    if (typeof lucide !== 'undefined') lucide.createIcons();
})();