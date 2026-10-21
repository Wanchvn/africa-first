/* ============================================================
   QAROTA — Client-side navigation
   Intercepts internal link clicks, fetches the target page,
   swaps only <main id="main">, updates history, and re-runs
   page-level init hooks. No full page reload.
   ============================================================ */

(function () {
    'use strict';

    // ----- Tunables -----
    var MAIN_ID = 'main';
    var TIMEOUT_MS = 12000;

    // Links we must NOT intercept (full reload is correct here)
    var SKIP_PREFIXES = [
        'mailto:', 'tel:', 'javascript:',
        'http://', 'https://' // offsite
    ];

    // Paths that should always do a full reload — chat has long-lived
    // JS state (poll timers, audio recorder streams) that's cleaner to
    // tear down by reloading than to re-init in place.
   var FULL_RELOAD_PATHS = [
    'login.php',
    'logout.php',
    'register.php'
];

    var currentAbort = null;
    var isNavigating = false;

    // ----- Init hooks -----
    // Pages can register functions to run after a swap by pushing
    // them onto window.__pageInit. After swap, we call them all.
    window.__pageInit = window.__pageInit || [];
    window.registerPageInit = function (fn) {
        if (typeof fn === 'function') window.__pageInit.push(fn);
    };
    function runPageInits() {
        // Also re-run anything interact.js registered on DOMContentLoaded
        // by re-dispatching a synthetic event — but only for the new DOM.
        (window.__pageInit || []).forEach(function (fn) {
            try { fn(); } catch (e) { console.error('page init error:', e); }
        });
        if (typeof lucide !== 'undefined' && lucide.createIcons) {
            lucide.createIcons();
        }
    }

    // ----- Progress bar -----
    var bar = null;
    function showProgress() {
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'nav-progress';
            bar.style.cssText =
                'position:fixed;top:0;left:0;height:2px;width:0;' +
                'background:#C65D3B;z-index:9999;transition:width 0.15s ease;';
            document.body.appendChild(bar);
        }
        bar.style.width = '15%';
        // crawl to ~75% while we wait
        var pct = 15;
        bar._timer = setInterval(function () {
            pct += (85 - pct) * 0.1;
            bar.style.width = pct + '%';
        }, 200);
    }
    function finishProgress() {
        if (!bar) return;
        clearInterval(bar._timer);
        bar.style.width = '100%';
        setTimeout(function () {
            if (bar) { bar.style.opacity = '0'; }
            setTimeout(function () {
                if (bar && bar.parentNode) bar.parentNode.removeChild(bar);
                bar = null;
            }, 200);
        }, 100);
    }

    // ----- Helpers -----
    function shouldSkip(anchor) {
        if (!anchor) return true;
        if (anchor.target && anchor.target !== '' && anchor.target !== '_self') return true;
        if (anchor.hasAttribute('download')) return true;
        if (anchor.dataset.noNav === '1') return true;

        var href = anchor.getAttribute('href');
        if (!href) return true;
        for (var i = 0; i < SKIP_PREFIXES.length; i++) {
            if (href.indexOf(SKIP_PREFIXES[i]) === 0) return true;
        }
        if (href.charAt(0) === '#') return true; // in-page anchor

        // Same-origin only
        try {
            var url = new URL(anchor.href, window.location.origin);
            if (url.origin !== window.location.origin) return true;
        } catch (e) { return true; }

        return false;
    }

    function isFullReloadPath(url) {
        var path = url.pathname.split('/').pop() || '';
        return FULL_RELOAD_PATHS.indexOf(path) !== -1;
    }

    function sameUrl(a, b) {
        try {
            var ua = new URL(a, window.location.origin);
            var ub = new URL(b, window.location.origin);
            return ua.pathname === ub.pathname && ua.search === ub.search;
        } catch (e) { return false; }
    }

    // ----- Core navigation -----
    function navigate(url, push) {
        if (isNavigating) return;
        isNavigating = true;

        if (currentAbort) currentAbort.abort();
        currentAbort = new AbortController();
        var timer = setTimeout(function () { currentAbort.abort(); }, TIMEOUT_MS);

        showProgress();

        fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: currentAbort.signal
        })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(function (html) {
            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');
            var newMain = doc.getElementById(MAIN_ID);
            var currentMain = document.getElementById(MAIN_ID);

            if (!newMain || !currentMain) {
                // Fallback: hard navigate
                window.location.href = url;
                return;
            }

            // Let the current page tear down its state (poll timers, MediaRecorder, etc.)
            document.dispatchEvent(new CustomEvent('qarota:beforeNavigate'));

            // Swap
            currentMain.replaceWith(newMain);

            // Page title
            var newTitle = doc.querySelector('title');
            if (newTitle) document.title = newTitle.textContent;

            // URL
            if (push !== false) {
                history.pushState({ nav: true }, '', url);
            }

            // Scroll to top (except when there's a hash)
            if (!url.split('#')[1]) window.scrollTo(0, 0);

            // Re-run inits
            runPageInits();

            // Notify
            document.dispatchEvent(new CustomEvent('qarota:navigated', { detail: { url: url } }));
        })
        .catch(function (err) {
            if (err && err.name === 'AbortError') return;
            console.error('Nav error:', err);
            // Fall back to a full navigation so the user still gets there
            window.location.href = url;
        })
        .finally(function () {
            clearTimeout(timer);
            isNavigating = false;
            finishProgress();
        });
    }

    // ----- Click interception -----
    document.addEventListener('click', function (e) {
        // Don't hijack modifier-clicks or middle-clicks
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        if (e.button !== 0) return;

        var anchor = e.target.closest('a');
        if (!anchor) return;
        if (shouldSkip(anchor)) return;

        var url = anchor.href;
        if (sameUrl(url, window.location.href)) {
            // Same page — let it reload normally to refresh state
            return;
        }
        if (isFullReloadPath(new URL(url, window.location.origin))) return;

        e.preventDefault();
        navigate(url, true);
    });

    // ----- Back / forward -----
    window.addEventListener('popstate', function () {
        navigate(window.location.href, false);
    });

    // ----- Initial state marker so back button behaves -----
    history.replaceState({ nav: true }, '', window.location.href);

})();