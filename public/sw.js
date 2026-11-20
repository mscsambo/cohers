/*
 * COHERS Mini App service worker: keeps the reporter pages usable without internet.
 *
 *  - "My reports" pages (/tg, /my, /my/new, /my/{report}, village lists): network first,
 *    the last good copy when offline or when the network is too slow.
 *  - Scripts, styles, fonts and icons (ours and the CDNs the Mini App uses): served from
 *    the cache at once and refreshed in the background.
 *
 * Reports written offline are NOT handled here: the page stores them in IndexedDB
 * (public/js/offline-queue.js) and sends them itself once the phone is back online.
 * Bump VERSION whenever this file changes.
 */
const VERSION = 'v1';
const PAGES = 'cohers-pages-' + VERSION;
const ASSETS = 'cohers-assets-' + VERSION;
const BASE = new URL(self.registration.scope).pathname;          // "/" or "/subfolder/"
const CDN_HOSTS = ['cdn.tailwindcss.com', 'telegram.org', 'fonts.googleapis.com', 'fonts.gstatic.com'];
const SLOW_NETWORK_MS = 6000;

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', event => {
    event.waitUntil((async () => {
        for (const key of await caches.keys()) {
            if (key.startsWith('cohers-') && key !== PAGES && key !== ASSETS) await caches.delete(key);
        }
        await self.clients.claim();
    })());
});

const isPage = path => path === BASE + 'tg' || path === BASE + 'my' || (path.startsWith(BASE + 'my/') && path !== BASE + 'my/sync-token');
const isStatic = path => /\.(js|css|png|svg|ico|webp|woff2?)$/.test(path) && path !== BASE + 'sw.js';

self.addEventListener('fetch', event => {
    const req = event.request;
    const url = new URL(req.url);

    if (req.method !== 'GET') {
        // Signing out removes the saved copies of the person's pages from this phone.
        if (req.method === 'POST' && url.origin === location.origin && url.pathname === BASE + 'logout') {
            event.waitUntil(caches.delete(PAGES));
        }
        return;
    }
    if (url.origin === location.origin) {
        if (isPage(url.pathname)) event.respondWith(networkFirst(event));
        else if (isStatic(url.pathname)) event.respondWith(staleWhileRevalidate(event));
    } else if (CDN_HOSTS.includes(url.hostname)) {
        event.respondWith(staleWhileRevalidate(event));
    }
});

async function networkFirst(event) {
    const req = event.request;
    const cache = await caches.open(PAGES);
    const network = fetch(req).then(res => {
        // Only real pages: never a redirect to the sign-in page or an error page.
        if (res.ok && res.type === 'basic' && !res.redirected) cache.put(req, res.clone());
        return res;
    });
    event.waitUntil(network.catch(() => {}));

    const cached = () => cache.match(req, { ignoreVary: true }).then(hit => hit || cache.match(req, { ignoreSearch: true, ignoreVary: true }));
    const slow = new Promise(resolve => setTimeout(resolve, SLOW_NETWORK_MS));
    try {
        // Weak signal: show the saved copy after a few seconds instead of a spinner for minutes.
        const first = await Promise.race([network, slow.then(cached)]);
        if (first) return first;
        return await network;
    } catch (e) {
        const hit = await cached();
        if (hit) return hit;
        // Any other "My reports" page falls back to the list (which also shows reports waiting to send).
        if (req.mode === 'navigate') {
            const home = await cache.match(BASE + 'my', { ignoreVary: true }) || await cache.match(BASE + 'my/new', { ignoreVary: true });
            if (home) return home;
        }
        return offlinePage();
    }
}

async function staleWhileRevalidate(event) {
    const req = event.request;
    const cache = await caches.open(ASSETS);
    const hit = await cache.match(req);
    const network = fetch(req).then(res => {
        if (res.ok || res.type === 'opaque') cache.put(req, res.clone());
        return res;
    });
    if (hit) {
        event.waitUntil(network.catch(() => {}));
        return hit;
    }
    return network;
}

function offlinePage() {
    return new Response(
        '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
        '<title>COHERS</title><body style="font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:90vh;text-align:center;padding:1.5rem;color:#1e293b">' +
        '<div><div style="font-size:2.5rem">📴</div><p>គ្មានអ៊ីនធឺណិត។ សូមព្យាយាមម្តងទៀតនៅពេលមានអ៊ីនធឺណិត។</p>' +
        '<p style="opacity:.7">No internet. Please try again when you are online.</p></div></body>',
        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}

// The page asks for its pages and the files they use to be saved, so they are ready offline.
self.addEventListener('message', event => {
    const data = event.data || {};
    if (data.type === 'warm' && Array.isArray(data.urls)) {
        event.waitUntil(Promise.all(data.urls.map(async u => {
            const url = new URL(u, self.registration.scope);
            const sameOrigin = url.origin === location.origin;
            try {
                if (sameOrigin && isPage(url.pathname)) {
                    const cache = await caches.open(PAGES);
                    const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
                    if (res.ok && res.type === 'basic' && !res.redirected) await cache.put(url.href, res);
                } else if (sameOrigin || CDN_HOSTS.includes(url.hostname)) {
                    const cache = await caches.open(ASSETS);
                    if (await cache.match(url.href)) return;
                    const res = await fetch(url, sameOrigin ? {} : { mode: 'no-cors' });
                    if (res.ok || res.type === 'opaque') await cache.put(url.href, res);
                }
            } catch (e) { /* offline or blocked: try again next time */ }
        })));
    } else if (data.type === 'forget') {
        event.waitUntil(caches.delete(PAGES));
    }
});
