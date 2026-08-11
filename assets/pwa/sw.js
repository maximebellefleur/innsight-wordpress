/* Innsight PWA service worker.
 *
 * Cache strategy:
 *   - install: pre-cache the map start URL + skin CSS/JS (via runtime
 *     path detection from the site origin).
 *   - fetch: network-first for HTML documents (fresh content wins),
 *     cache-first for static assets (fast repeat loads, works offline).
 *   - version-bumped cache name so a plugin update evicts stale
 *     resources on the next activate cycle.
 */
const CACHE = 'innsight-pwa-v1';
const ORIGIN = self.location.origin;

self.addEventListener('install', function (e) {
    self.skipWaiting();
    e.waitUntil(
        caches.open(CACHE).then(function (cache) {
            // We deliberately let each add fail silently - the map page
            // URL is site-specific and may not exist on every install.
            return Promise.all([
                cache.add(ORIGIN + '/').catch(function () {}),
            ]);
        })
    );
});

self.addEventListener('activate', function (e) {
    e.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
        }).then(function () { return self.clients.claim(); })
    );
});

self.addEventListener('fetch', function (e) {
    var req = e.request;
    if (req.method !== 'GET') return;
    var url = new URL(req.url);
    if (url.origin !== ORIGIN) return;

    // Network-first for HTML - always show the latest page when online,
    // fall back to whatever's cached when offline.
    var isHTML = req.headers.get('accept') && req.headers.get('accept').indexOf('text/html') !== -1;
    if (isHTML) {
        e.respondWith(
            fetch(req).then(function (res) {
                var copy = res.clone();
                caches.open(CACHE).then(function (c) { c.put(req, copy); }).catch(function () {});
                return res;
            }).catch(function () { return caches.match(req); })
        );
        return;
    }

    // Cache-first for static assets (images, CSS, JS, fonts).
    e.respondWith(
        caches.match(req).then(function (cached) {
            if (cached) return cached;
            return fetch(req).then(function (res) {
                if (res && res.status === 200 && res.type === 'basic') {
                    var copy = res.clone();
                    caches.open(CACHE).then(function (c) { c.put(req, copy); }).catch(function () {});
                }
                return res;
            });
        })
    );
});
