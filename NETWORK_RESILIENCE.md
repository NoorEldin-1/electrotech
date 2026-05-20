# Network Resilience Architecture

ElectroTech runs in a factory environment with weak, unstable internet. This
document explains the layered defenses we use to keep the application usable
on a 200 kbps, high-latency, lossy link — and how to test, tune, and operate
them.

## TL;DR

We did **not** rewrite the application as offline-first; Filament is
fundamentally server-rendered (Livewire), so each interaction is a server
round-trip. Instead, we layered six narrow defenses that, together, turn a
single dropped packet from a data-loss event into an invisible retry:

| Layer | Mechanism | File |
|---|---|---|
| Wire bytes | gzip/brotli compression | [app/Http/Middleware/CompressResponse.php](app/Http/Middleware/CompressResponse.php) |
| Asset re-fetch | `Cache-Control: immutable` on Vite output | [app/Http/Middleware/StaticAssetCacheControl.php](app/Http/Middleware/StaticAssetCacheControl.php) |
| Write replay | server-side `Idempotency-Key` deduplication | [app/Http/Middleware/Idempotency.php](app/Http/Middleware/Idempotency.php) |
| Session loss | lightweight `/admin/ping` keepalive | [app/Http/Controllers/PingController.php](app/Http/Controllers/PingController.php) |
| Offline UX | Service Worker + IndexedDB write queue | [public/js/service-worker.js](public/js/service-worker.js) |
| Draft loss | per-field `localStorage` autosave | [public/js/network-resilience.js](public/js/network-resilience.js) |

All six are wired in via [bootstrap/app.php](bootstrap/app.php) and the
`PanelsRenderHook::BODY_END` hook in
[app/Providers/Filament/AdminPanelProvider.php](app/Providers/Filament/AdminPanelProvider.php).

## Config & kill switch

The whole resilience layer is gated by [config/resilience.php](config/resilience.php).
**Defaults: OFF on `APP_ENV=local`, ON everywhere else** — because
`php artisan serve` does not always preserve binary bodies with
`Content-Encoding`, and because an over-aggressive Service Worker on
localhost can hide Livewire/Vite issues you actually need to see.

Override via .env:

```dotenv
NETWORK_RESILIENCE_ENABLED=true         # master switch
NETWORK_RESILIENCE_COMPRESS=true        # gzip/brotli responses
NETWORK_RESILIENCE_SW=true              # register the Service Worker
NETWORK_RESILIENCE_IDEMPOTENCY=true     # Idempotency-Key middleware
```

When the master switch is OFF, the AdminPanelProvider injects a tiny
inline kill-switch instead of the resilience script: it unregisters any
previously-registered Service Worker and deletes any `electrotech-*`
caches. So **flipping `NETWORK_RESILIENCE_ENABLED=false` and reloading
once** is the recovery path if a SW from a prior activation is causing
problems — no DevTools surgery required.

## Hard-learned safety guarantees

These came out of debugging a blank-dashboard symptom early on:

- **The JS never monkey-patches `window.fetch`.** Wrapping the global
  primitive broke Livewire 3's lazy-component bootstrap on certain call
  shapes, which left Alpine uninitialised, which kept the entire UI
  hidden behind `[x-cloak]{display:none}`. We stamp `Idempotency-Key`
  via the axios request interceptor only (Livewire 3 uses axios for
  writes).
- **The Service Worker does not call `clients.claim()`** and **does not
  intercept navigation requests.** A buggy SW that returns a wrong
  response for a navigation silently blanks the dashboard. The SW now
  only caches `/build/assets/*` and queues failed writes — both
  side-channels that can't break a live render.
- **Every JS section is wrapped in its own try/catch.** A failure in,
  say, the draft autosave will not propagate up and stop the connection
  pill or the idempotency stamping from working.
- **Compression skips bodies under 1 KB** and **skips already-encoded
  responses**, and is OFF by default on `APP_ENV=local` because the
  built-in PHP dev server has known issues with binary stdout.

## What problem each layer solves

### 1. CompressResponse — wire bytes
**Symptom on weak link:** A Filament list page renders ~80 KB of HTML. On a
200 kbps link that's >3 seconds *before* TCP slow-start kicks in.

**Fix:** Brotli (preferred) or gzip the response body when the client
supports it. Skips streamed, binary, already-encoded, or <1 KB bodies.
Typical ratio on Filament HTML is 8–10×.

**Tuning:** Compression level is intentionally low (br=4, gzip=5) to
minimize CPU cost — the link, not the CPU, is the bottleneck.

### 2. StaticAssetCacheControl — eliminate re-fetches
**Symptom on weak link:** Filament SPA navigation refers to CSS/JS that
the browser may re-validate, costing a round-trip even when the file
hasn't changed.

**Fix:** Vite emits content-hashed filenames, so the contents at a given
URL never change. We mark them `public, max-age=31536000, immutable` so
the browser never asks again. Resilience scripts themselves get a short
TTL (60 s) so deploys roll out fast.

### 3. Idempotency — safe replays
**Symptom on weak link:** User clicks "Start Work Order." The request
reaches the server, the server updates the DB, but the response is lost
on the way back. The browser retries. A second Work Order starts. Stock
is double-deducted.

**Fix:** State-changing requests (`POST`/`PUT`/`PATCH`/`DELETE`) carry an
`Idempotency-Key` header (stamped automatically by
[public/js/network-resilience.js](public/js/network-resilience.js)). The
middleware caches the response in Redis for 24 h keyed by `(user, key)`.
Replays return the cached response without re-executing the handler.
Concurrent retries (e.g. background sync drains while the user is also
acting) get `409 Conflict` with `Retry-After: 2`.

**Important constraints:**
- 5xx responses are **not** cached — a transient server failure should
  not lock the user into an unrecoverable state. Their retry actually
  retries.
- Replayed responses carry an `Idempotent-Replay: true` header so the
  client can distinguish replay from fresh.
- Keys are scoped per-user; users cannot replay each other's responses.
- The Filament WO state-transition actions
  ([app/Filament/Resources/WorkOrderResource.php](app/Filament/Resources/WorkOrderResource.php))
  also re-read the record from DB and treat "already advanced" as
  success — belt and suspenders.

### 4. PingController — session and connectivity probe
**Symptom on weak link:** An operator composing a long Project description
takes 25 minutes. The session times out (default 120 m, but extreme idle
+ packet loss can race the heartbeat). Submit → 419 Page Expired → all
form data lost.

**Fix:** Browser pings `/admin/ping` every 60 s and on
`online`/`visibilitychange`. The endpoint writes one timestamp to the
session, which extends the cookie's idle TTL. RTT is measured client-side
to classify the link as `good`/`weak`/`offline` and surface a status pill.

### 5. Service Worker — offline shell + write queue
**Symptom on weak link:** Connection drops mid-action. User stares at a
spinner forever, then the browser shows its default offline page. Their
queued action is lost.

**Fix:** [public/js/service-worker.js](public/js/service-worker.js):
- Caches `/build/assets/*` cache-first so navigation works even when DNS
  is dead.
- Serves a small `offline-shell` HTML on navigation when the network is
  gone, instead of the browser's default error.
- Catches `TypeError` (real network failure) on `POST`/`PUT`/`PATCH`/
  `DELETE`, stores the request body + headers + URL in IndexedDB, and
  replies `202 Accepted` so the client UI doesn't show a hard error.
- Drains the queue on `online`, `sync` events, and explicit `drain`
  postMessage from the page. Each replayed request carries its original
  `Idempotency-Key`, so the server safely deduplicates if the original
  actually arrived.

### 6. localStorage draft autosave
**Symptom on weak link:** User types a 500-word Work Order description.
Connection drops. They reload. Empty form.

**Fix:** [public/js/network-resilience.js](public/js/network-resilience.js)
hooks every `textarea`/`input[type=text|email|number][name=]` inside a
`<form>`, debounces input events at 2 s, and writes
`{value, timestamp}` to `localStorage` keyed by
`(pathname, field-name)`. On the next visit to the same URL, an
unobtrusive banner offers to restore. Drafts purge themselves after 7
days, and on successful form submit.

## Client-side behavior

In addition to the layers above, [network-resilience.js](public/js/network-resilience.js):

- Registers the Service Worker on `load` so it doesn't compete with the
  first paint for bandwidth.
- Wraps `window.fetch` and `window.axios` so every state-changing
  request gets an `Idempotency-Key` if the caller didn't supply one. This
  catches Livewire 3's internal fetches automatically, so all Filament
  action submissions inherit the protection.
- Guards submit buttons against double-clicks (2 s soft disable). Absorbs
  the "did anything happen?" double-click reflex on slow links.
- Re-bootstraps on `livewire:navigated` because Filament's `->spa()`
  mode replaces the DOM without firing `DOMContentLoaded`.

## Testing strategy

The full test suite for the resilience layer is in
[tests/Feature/NetworkResilienceTest.php](tests/Feature/NetworkResilienceTest.php).

```bash
php artisan test --filter=NetworkResilienceTest
# 15 passed
```

The tests deliberately simulate the FAILURE MODES of a weak link, not
just happy paths:

- **Replayed write executes once** — the foundational guarantee.
- **Distinct keys execute independently** — guards against an
  over-eager dedupe bug.
- **Missing key does not cache** — must not break sequential writes.
- **Malformed key rejected** — keys are attacker-influenced.
- **5xx not cached** — transient errors must remain retriable.
- **Replay tagged** — clients can tell replay from fresh.
- **Per-user scope** — no cross-user leakage.
- **Compression triggers on large bodies** — but **not** on tiny ones (CPU
  vs bytes tradeoff) and **not** for clients that don't accept it.
- **Build assets marked immutable** with the long-lived Cache-Control.
- **Resilience scripts get short TTL** — for fast rollout.
- **Ping endpoint is auth-gated but CSRF-exempt** — works with the SW
  heartbeat.
- **WO state-transition is idempotent against double-submit** — the
  service-layer + Filament-action guard combination.

### Manually simulating a weak link

Chrome DevTools → Network → Throttling → **Custom**:
- Download 200 kbps, Upload 100 kbps, Latency 800 ms.

To go further (packet loss, jitter): use `tc qdisc` on Linux or
[clumsy](https://jagt.github.io/clumsy/) on Windows. Suggested profile:

```
loss: 10%
delay: 500 ms ± 200 ms jitter
```

Walk through these scenarios:
1. Start a Work Order → unplug Ethernet immediately. Plug back in. The
   write should appear in the queue toast, then sync — never double-
   create.
2. Open a Project edit form. Type a long description. Disable Wi-Fi.
   Reload. The "Restore draft?" banner should appear.
3. Submit the same QA approval twice rapidly. Server should record one
   approval; UI should show success both times.
4. Stop the queue worker for 5 minutes. Operators continue working — the
   `materials` queue jobs are durable via Redis with the existing
   backoff/retry semantics. When the worker comes back, work resumes.

## Operations & infrastructure recommendations

These complement the in-code defenses; they live at the deploy layer.

### Reverse proxy / nginx

```nginx
# Long keepalive timeout — operators on shaky links should not be forced
# to renegotiate TLS every request.
keepalive_timeout 75s;
keepalive_requests 1000;

# Tolerate a slow client uploading a form on 100 kbps without dropping.
client_body_timeout 60s;
client_header_timeout 60s;

# Send the Filament HTML body in one go on slow links rather than chunk-
# by-chunk; chunked encoding adds protocol overhead.
tcp_nopush on;
tcp_nodelay on;

# Gzip at the proxy is also fine — our middleware no-ops when
# Content-Encoding is already set.
gzip on;
gzip_types text/html text/css application/javascript application/json;
```

### MySQL

```ini
# A flaky link should not exhaust the connection pool. Drop idle
# connections faster than the OS-level half-open timeout.
wait_timeout = 600
interactive_timeout = 600
max_connections = 200
```

### PHP-FPM

```ini
# Long enough for a slow link to receive the response, short enough that
# a truly hung request is reaped before backing up the worker pool.
request_terminate_timeout = 90
```

### Redis

```
# Atomic-lock semantics depend on persistence. The default RDB cadence
# is acceptable; if you raise `save` thresholds, also enable AOF so the
# idempotency-replay cache survives a crash.
appendonly yes
```

## Future work

Things we considered and intentionally deferred:

- **WebSockets / Pusher** for real-time WO status. Adds another fragile
  long-lived TCP connection; the polling-via-pings pattern is more
  forgiving on the link. Reconsider once the link is upgraded.
- **CRDT-style merge of conflicting offline edits.** Out of scope —
  factory workflow is single-owner-per-record (one operator owns a WO
  at a time). The idempotency layer is enough.
- **Aggressive Filament-level query result caching.** The existing
  Redis-cached stat widgets cover the dashboard. Wider result caching
  needs careful invalidation and is best done per-screen on demand.

## Files added by this change

- `app/Http/Middleware/CompressResponse.php`
- `app/Http/Middleware/StaticAssetCacheControl.php`
- `app/Http/Middleware/Idempotency.php`
- `app/Http/Controllers/PingController.php`
- `public/js/network-resilience.js`
- `public/js/service-worker.js`
- `tests/Feature/NetworkResilienceTest.php`
- `NETWORK_RESILIENCE.md` (this file)

## Files modified

- `bootstrap/app.php` — register the three middleware globally + CSRF
  exemption for `/admin/ping`.
- `routes/web.php` — add the `/admin/ping` route.
- `app/Providers/Filament/AdminPanelProvider.php` — inject the
  resilience script via `BODY_END`.
- `app/Filament/Resources/WorkOrderResource.php` — convert the four WO
  state-transition actions (start / submit_qa / approve_qa / complete)
  to re-fetch and treat "already advanced" as success on retry.
