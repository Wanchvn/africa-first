/**
 * Qarota — Service Worker
 *
 * Strategy:
 *   - Static assets (CSS, JS, images, fonts): cache-first
 *   - HTML document navigations: network-first, cache fallback
 *   - Everything else (XHR, AJAX, PHP endpoints): pass through, never touch
 *
 * IMPORTANT: this SW must never intercept .php requests or XHR/fetch
 * calls. If it does and the fetch hangs, the whole app hangs.
 */

const CACHE_NAME = 'qarota-v2';

// Relative paths so this works whether Qarota is served from
// /qarota/ (local dev) or / (production root).
const STATIC_ASSETS = [
    'assets/css/style.css',
    'assets/js/interact.js',
    'assets/js/nav.js',
    'assets/js/lucide.min.js',
    'assets/img/logo-icon.PNG',
    'assets/img/icon-192.png',
    'assets/img/icon-512.png',
    'manifest.json'
];

// ---- Install: pre-cache the app shell ----
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                // addAll() rejects the whole batch if any single file 404s.
                // Cache each URL individually so one missing file doesn't
                // kill the entire install.
                return Promise.all(
                    STATIC_ASSETS.map((url) =>
                        cache.add(url).catch((err) => {
                            console.warn('[sw] Could not pre-cache:', url, err);
                        })
                    )
                );
            })
            .then(() => self.skipWaiting())
    );
});

// ---- Activate: clean up old caches ----
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((key) => key !== CACHE_NAME)
                    .map((key) => {
                        console.log('[sw] Deleting old cache:', key);
                        return caches.delete(key);
                    })
            ))
            .then(() => self.clients.claim())
    );
});

// ---- Fetch handler ----
self.addEventListener('fetch', (event) => {
    const req = event.request;

    // Only GET requests. POSTs, PUTs, DELETEs go straight to the network.
    if (req.method !== 'GET') return;

    // Same-origin only. Cross-origin (extensions, fonts CDN, etc.) → browser handles.
    let url;
    try { url = new URL(req.url); } catch (e) { return; }
    if (url.origin !== self.location.origin) return;

    const path = url.pathname;

    // ---------------------------------------------------------------
    // Rule 1: NEVER intercept .php requests.
    // These are dynamic. Letting the SW touch them caused the freeze.
    // ---------------------------------------------------------------
    if (path.endsWith('.php')) return;

    // ---------------------------------------------------------------
    // Rule 2: static assets → cache-first
    // ---------------------------------------------------------------
    const isStatic = /\.(css|js|png|jpg|jpeg|gif|webp|svg|ico|woff2?|ttf|eot|json)$/i.test(path);

    if (isStatic) {
        event.respondWith(
            caches.match(req).then((cached) => {
                if (cached) return cached;

                return fetch(req).then((res) => {
                    // Cache only successful same-origin 200s.
                    if (res && res.status === 200 && res.type === 'basic') {
                        const copy = res.clone();
                        caches.open(CACHE_NAME)
                            .then((c) => c.put(req, copy))
                            .catch(() => {});
                    }
                    return res;
                }).catch(() => {
                    // Network failed. If we had a cached copy, use it.
                    // (We already returned it above if it existed.)
                    return new Response('', { status: 504, statusText: 'Offline' });
                });
            })
        );
        return;
    }

    // ---------------------------------------------------------------
    // Rule 3: HTML document navigations → network-first, cache fallback
    // ---------------------------------------------------------------
    const isDocument = req.mode === 'navigate' || req.destination === 'document';

    if (isDocument) {
        event.respondWith(
            fetch(req)
                .then((res) => {
                    if (res && res.status === 200 && res.type === 'basic') {
                        const copy = res.clone();
                        caches.open(CACHE_NAME)
                            .then((c) => c.put(req, copy))
                            .catch(() => {});
                    }
                    return res;
                })
                .catch(() => {
                    return caches.match(req).then((cached) => {
                        if (cached) return cached;
                        return new Response('Offline', {
                            status: 503,
                            headers: { 'Content-Type': 'text/plain' }
                        });
                    });
                })
        );
        return;
    }

    // ---------------------------------------------------------------
    // Rule 4: everything else → do NOT call respondWith().
    // The browser handles it natively, exactly as if the SW didn't exist.
    // This is the critical fix. Any request we don't handle explicitly
    // must fall through here so it can't hang.
    // ---------------------------------------------------------------
    return;
});