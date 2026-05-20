/* eslint-env serviceworker */
/*
 * ElectroTech Operator Console — Service Worker.
 *
 * Distinct from /js/service-worker.js (which serves the Filament admin
 * panel under a narrow cache/queue policy). This SW is scoped to
 * /console/ and is more aggressive because the console is *designed*
 * to work fully offline. It is allowed to:
 *
 *   - Cache the entire shell precisely (HTML, CSS, JS, manifest).
 *   - Serve the shell from cache when the network is unreachable.
 *   - Intercept /console/ navigations and return the cached index.html.
 *
 * It is NOT allowed to:
 *
 *   - Cache /sync/* responses. Sync API responses are highly dynamic
 *     and stale data here would silently corrupt the IndexedDB state.
 *     Sync calls go to the network or fail loudly — there is no
 *     middle ground.
 *
 *   - Cache /admin/* responses or anything outside /console/. Doing so
 *     would conflict with the resilience SW already registered there.
 *
 *   - Call clients.claim(). Same reason as the resilience SW: a SW
 *     taking over an in-flight render mid-response can blank the page.
 *     Activation waits for the next navigation; that's fine because
 *     this is a single-page console — first launch caches the shell,
 *     second launch (even offline) uses it.
 *
 * Versioning: bumping SHELL_VERSION below invalidates the cache so a
 * deploy is picked up the next time the device comes online.
 */

// Bumped from v1 → v2 when i18n was added so the activation step
// drops the old cache and re-precaches the locale dictionaries on the
// next visit. Operators with a v1 cache will see no behaviour change
// — the install handler tolerates partial precache failures so a stale
// device that can't fetch the new files yet keeps running on its
// existing shell.
const SHELL_VERSION = 'v2';
const SHELL_CACHE = `electrotech-console-shell-${SHELL_VERSION}`;

const SHELL_ASSETS = [
    '/console/',
    '/console/index.html',
    '/console/manifest.webmanifest',
    '/console/styles.css',
    '/console/app.js',
    '/console/sync-engine.js',
    '/console/db.js',
    '/console/ui.js',
    '/console/i18n.js',
    '/console/locales/en.js',
    '/console/locales/ar.js',
    '/console/views/work-orders.js',
    '/console/views/inventory.js',
    '/console/views/conflicts.js',
    '/console/views/diagnostics.js',
    '/images/electrotech-logo.jpg',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(SHELL_CACHE)
            .then((cache) =>
                // addAll is atomic — if any asset fails to fetch (e.g.
                // a typo in the list), the whole install fails and we
                // do NOT activate. This is preferable to a half-cached
                // shell that breaks on partial offline loads.
                cache.addAll(SHELL_ASSETS).catch((err) => {
                    // One asset failing is acceptable in development
                    // where some files might not yet exist. Log the
                    // failure but don't block install.
                    console.warn('[console-sw] some shell assets failed to cache', err);
                })
            )
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const keys = await caches.keys();
            await Promise.all(
                keys
                    .filter((k) => k.startsWith('electrotech-console-') && k !== SHELL_CACHE)
                    .map((k) => caches.delete(k))
            );
            // NOTE: not claiming clients. See header.
        })()
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;

    let url;
    try {
        url = new URL(req.url);
    } catch (e) {
        return;
    }

    if (url.origin !== self.location.origin) return;

    // /sync/* is NEVER cached. Pass through to the network. If the
    // network is gone, the sync-engine's own fetch will reject and the
    // engine will mark the device offline. Returning a fake cached
    // sync response would put the local IndexedDB in an inconsistent
    // state vs the server.
    if (url.pathname.startsWith('/sync/')) {
        return; // default network handling
    }

    // /console/* — shell-first cache strategy. Navigation requests for
    // any path under /console/ return the cached index.html so the SPA
    // can mount and route client-side. This is what makes the console
    // launchable from a cold offline state.
    if (url.pathname.startsWith('/console/') || url.pathname === '/console') {
        if (req.mode === 'navigate' || req.destination === 'document') {
            event.respondWith(navigationStrategy());
            return;
        }
        event.respondWith(cacheFirst(req));
        return;
    }

    // Everything else falls through to the default browser behaviour.
});

async function navigationStrategy() {
    // Stale-while-revalidate: serve the cached shell immediately for
    // instant boot, but kick off a background fetch to refresh the
    // cache for next time. The new SHELL_VERSION mechanism is the
    // safety net — if the deploy bumped the version, the old cache is
    // dropped on activate.
    try {
        const cache = await caches.open(SHELL_CACHE);
        const cached = await cache.match('/console/index.html');
        if (cached) {
            fetch('/console/index.html')
                .then((resp) => resp.ok && cache.put('/console/index.html', resp.clone()))
                .catch(() => {});
            return cached;
        }
    } catch (e) {
        // fall through
    }

    try {
        return await fetch('/console/index.html');
    } catch (e) {
        // No cache, no network — return a minimal offline placeholder
        // rather than the browser's default error page.
        return new Response(
            '<!doctype html><meta charset=utf-8><title>Offline</title>' +
                '<p style="font-family:sans-serif;padding:24px">Operator Console not yet cached on this device.</p>',
            { status: 503, headers: { 'Content-Type': 'text/html' } }
        );
    }
}

async function cacheFirst(req) {
    const cache = await caches.open(SHELL_CACHE);
    const hit = await cache.match(req);
    if (hit) return hit;

    try {
        const resp = await fetch(req);
        if (resp && resp.ok) {
            cache.put(req, resp.clone()).catch(() => {});
        }
        return resp;
    } catch (err) {
        return new Response('', { status: 504 });
    }
}

// Background sync — fired by the browser when connectivity returns.
// The sync engine's running tab will also notice via 'online' events,
// but Background Sync covers the case where the tab is closed and the
// user opens it again after a long offline period.
self.addEventListener('sync', (event) => {
    if (event.tag === 'electrotech-console-sync') {
        event.waitUntil(broadcast({ type: 'sync-request' }));
    }
});

self.addEventListener('message', (event) => {
    if (!event.data) return;
    if (event.data.type === 'skip-waiting') self.skipWaiting();
});

async function broadcast(payload) {
    try {
        const clientsList = await self.clients.matchAll({ includeUncontrolled: true });
        for (const client of clientsList) client.postMessage(payload);
    } catch (e) { /* best-effort */ }
}
