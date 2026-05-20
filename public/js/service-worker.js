/* eslint-env serviceworker */
/*
 * ElectroTech network-resilience Service Worker.
 *
 * Conservative scope by design — we removed two formerly broader
 * responsibilities that proved too aggressive in practice:
 *
 *   1. `clients.claim()` is NOT called. The SW takes effect only on the
 *      NEXT navigation after registration. This avoids it suddenly
 *      taking over the current page mid-render and returning unexpected
 *      responses to in-flight Livewire requests.
 *
 *   2. Navigation requests are NOT intercepted. We do not serve an
 *      offline shell, because a buggy SW returning the shell for a live
 *      page would silently blank the dashboard. The cost is that we
 *      cannot show a friendly offline screen — that's an acceptable
 *      trade for never breaking a working page.
 *
 * What we DO keep:
 *
 *   A. STATIC ASSET CACHE — fingerprinted Vite output (`/build/assets/*`)
 *      is cached cache-first. The HTTP layer also marks them immutable,
 *      so once the browser has them it never re-fetches them.
 *
 *   B. WRITE REPLAY QUEUE — POST/PUT/PATCH/DELETE requests that fail at
 *      the fetch level (TypeError = network gone) get queued in
 *      IndexedDB with their Idempotency-Key. When connectivity returns
 *      we replay them in order; the server-side idempotency middleware
 *      ensures no double application even if the original request
 *      actually went through and we just didn't see the response.
 */

const VERSION = 'v2';
const STATIC_CACHE = `electrotech-static-${VERSION}`;
const DB_NAME = 'electrotech-resilience';
const DB_STORE = 'pending-writes';

// ---------- Install / activate ------------------------------------------------

self.addEventListener('install', (event) => {
    // No pre-caching; we cache assets lazily on first hit. skipWaiting so
    // a new SW version activates promptly (it still won't claim the
    // current page — only the next navigation).
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const expected = new Set([STATIC_CACHE]);
            const keys = await caches.keys();
            await Promise.all(
                keys
                    .filter((k) => k.indexOf('electrotech-') === 0 && !expected.has(k))
                    .map((k) => caches.delete(k))
            );
            // NOTE: deliberately NOT calling clients.claim() — see header.
        })()
    );
});

// ---------- Fetch dispatch ----------------------------------------------------

self.addEventListener('fetch', (event) => {
    const req = event.request;

    let url;
    try {
        url = new URL(req.url);
    } catch (e) {
        return;
    }

    // Only handle our own origin. Don't interfere with CDN / analytics / etc.
    if (url.origin !== self.location.origin) return;

    if (req.method === 'GET') {
        // Fingerprinted Vite assets: cache-first, very long-lived.
        // Everything else: let the browser handle normally — we do NOT
        // intercept navigation or other GETs.
        if (url.pathname.indexOf('/build/assets/') === 0) {
            event.respondWith(cacheFirst(req, STATIC_CACHE));
        }
        return;
    }

    // State-changing verbs: try network, queue on failure.
    if (['POST', 'PUT', 'PATCH', 'DELETE'].indexOf(req.method) !== -1) {
        event.respondWith(networkOrQueue(req));
    }
});

// ---------- Strategies --------------------------------------------------------

async function cacheFirst(req, cacheName) {
    try {
        const cache = await caches.open(cacheName);
        const hit = await cache.match(req);
        if (hit) return hit;

        const resp = await fetch(req);
        if (resp && resp.ok) {
            cache.put(req, resp.clone()).catch(() => {});
        }
        return resp;
    } catch (err) {
        // Nothing in cache and no network: pass through the error as a
        // 504 so the page can decide how to surface it. We do NOT
        // synthesise a fake 200 — that would be lying to Livewire.
        return new Response('', { status: 504, statusText: 'Asset unavailable offline' });
    }
}

async function networkOrQueue(req) {
    try {
        return await fetch(req.clone());
    } catch (err) {
        try {
            await queueRequest(req);
            broadcast({ type: 'write-queued', url: req.url, method: req.method });
            return new Response(
                JSON.stringify({
                    queued: true,
                    message: 'Saved locally. Will sync when connection returns.',
                }),
                {
                    status: 202,
                    headers: { 'Content-Type': 'application/json' },
                }
            );
        } catch (queueErr) {
            return new Response(
                JSON.stringify({ queued: false, error: queueErr.message }),
                { status: 503, headers: { 'Content-Type': 'application/json' } }
            );
        }
    }
}

// ---------- Pending-write queue (IndexedDB) -----------------------------------

function openDb() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, 1);
        req.onupgradeneeded = () => {
            req.result.createObjectStore(DB_STORE, { keyPath: 'id', autoIncrement: true });
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

async function queueRequest(req) {
    const body = await req.clone().text();
    const headers = {};
    req.headers.forEach((v, k) => { headers[k] = v; });

    const db = await openDb();
    await new Promise((resolve, reject) => {
        const tx = db.transaction(DB_STORE, 'readwrite');
        tx.objectStore(DB_STORE).add({
            url: req.url,
            method: req.method,
            headers,
            body,
            queuedAt: Date.now(),
        });
        tx.oncomplete = resolve;
        tx.onerror = () => reject(tx.error);
    });
}

async function drainQueue() {
    const db = await openDb();
    const tx = db.transaction(DB_STORE, 'readwrite');
    const store = tx.objectStore(DB_STORE);
    const all = await new Promise((resolve, reject) => {
        const r = store.getAll();
        r.onsuccess = () => resolve(r.result);
        r.onerror = () => reject(r.error);
    });

    for (const entry of all) {
        try {
            const resp = await fetch(entry.url, {
                method: entry.method,
                headers: entry.headers,
                body: entry.body,
                credentials: 'same-origin',
            });

            // 2xx, 4xx (deterministic), or replayed-idempotent → drop
            // from queue. Only retry on transient 5xx / network failure.
            if (resp.status < 500 || resp.headers.get('Idempotent-Replay') === 'true') {
                await deleteEntry(entry.id);
                broadcast({ type: 'write-synced', url: entry.url, status: resp.status });
            }
        } catch (err) {
            // Network still down. Stop draining; we'll retry on the next
            // online event.
            return;
        }
    }
}

async function deleteEntry(id) {
    const db = await openDb();
    await new Promise((resolve, reject) => {
        const tx = db.transaction(DB_STORE, 'readwrite');
        tx.objectStore(DB_STORE).delete(id);
        tx.oncomplete = resolve;
        tx.onerror = () => reject(tx.error);
    });
}

// ---------- Drain triggers ----------------------------------------------------

self.addEventListener('sync', (event) => {
    if (event.tag === 'electrotech-drain') {
        event.waitUntil(drainQueue());
    }
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'drain') {
        event.waitUntil(drainQueue());
    }
});

// ---------- Client broadcast --------------------------------------------------

async function broadcast(payload) {
    try {
        const clientsList = await self.clients.matchAll({ includeUncontrolled: true });
        for (const client of clientsList) client.postMessage(payload);
    } catch (e) { /* best-effort */ }
}
