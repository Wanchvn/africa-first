/**
 * Qarota — Service Worker
 *
 * Caches the app shell (static assets) so Qarota loads fast on repeat visits.
 * Uses a network-first strategy for HTML (always tries fresh content) and
 * cache-first for static assets (CSS, JS, images).
 *
 * No offline mode for dynamic content (feed, DMs) yet — that requires
 * API-aware caching which we can add later.
 */

const CACHE_NAME = 'qarota-v1';
const STATIC_ASSETS = [
    '/qarota/assets/css/style.css',
    '/qarota/assets/js/interact.js',
    '/qarota/assets/js/lucide.min.js',
    '/qarota/assets/img/logo-icon.PNG',
    '/qarota/assets/img/icon-192.png',
    '/qarota/assets/img/icon-512.png',
    '/qarota/manifest.json'
];

// ---- Install: pre-cache the app shell ----
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS).catch((err) => {
                console.warn('[sw] Pre-cache partial failure:', err);
            });
        }).then(() => self.skipWaiting())
    );
});

// ---- Activate: clean up old caches ----
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.filter((key) => key !== CACHE_NAME)
                    .map((key) => caches.delete(key))
            );
        }).then(() => self.clients.claim())
    );
});

// ---- Fetch: strategy per request type ----
self.addEventListener('fetch', (event) => {
    const req = event.request;

    // Only handle GET
    if (req.method !== 'GET') return;

    // Skip cross-origin (extension requests, etc.)
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    // Skip admin/API/AJAX endpoints — they should always hit the network
    const skipPaths = [
        '/message_send.php',
        '/message_voice_send.php',
        '/message_image_send.php',
        '/message_delete.php',
        '/messages_fetch.php',
        '/typing_ping.php',
        '/interact.php',
        '/follow.php',
        '/block.php',
        '/user_card.php',
        '/notifications.php',
        '/admin_reports.php'
    ];
    if (skipPaths.some((p) => url.pathname.endsWith(p))) return;

    // Static assets → cache-first
    const isStatic = /\.(css|js|png|jpg|jpeg|gif|webp|svg|woff2?|ttf|ico)$/.test(url.pathname);

    if (isStatic) {
        event.respondWith(
            caches.match(req).then((cached) => {
                if (cached) return cached;
                return fetch(req).then((res) => {
                    if (res && res.status === 200 && res.type === 'basic') {
                        const copy = res.clone();
                        caches.open(CACHE_NAME).then((c) => c.put(req, copy));
                    }
                    return res;
                }).catch(() => cached);
            })
        );
        return;
    }

    // HTML and everything else → network-first, cache as fallback
    event.respondWith(
        fetch(req)
            .then((res) => {
                // Cache fresh HTML for offline fallback
                if (res && res.status === 200 && res.type === 'basic' && req.destination === 'document') {
                    const copy = res.clone();
                    caches.open(CACHE_NAME).then((c) => c.put(req, copy));
                }
                return res;
            })
            .catch(() => caches.match(req).then((cached) => {
                if (cached) return cached;
                // Final fallback: offline page (optional)
                return caches.match('/qarota/offline.php');
            }))
    );
});