/*
 * ElectroTech network-resilience client layer.
 *
 * Loaded into the Filament admin panel via PanelsRenderHook::BODY_END
 * only when config('resilience.enabled') is true.
 *
 * DEFENSIVE BY DESIGN. The factory app must keep working even if any
 * piece of this file throws — Filament/Alpine hide all UI behind
 * `[x-cloak]{display:none}` until Alpine boots, so an uncaught error in
 * a script loaded BEFORE Alpine's init runs would silently produce a
 * blank dashboard. Every block below is wrapped in its own try/catch
 * and reports failures to the console without escaping.
 *
 * Responsibilities (all UI-facing, complementing the server-side
 * middleware stack):
 *
 *   1. Register the service worker (when enabled in config).
 *   2. Heartbeat /admin/ping every 60s, measure RTT, classify the
 *      connection as good / weak / offline, surface a small status pill.
 *   3. Stamp axios state-changing requests with an Idempotency-Key so
 *      the server can dedupe replays. We deliberately do NOT monkey-
 *      patch window.fetch — that interfered with Livewire 3's internal
 *      lazy-component bootstrap. Livewire uses axios for AJAX writes,
 *      so the axios interceptor covers them.
 *   4. Auto-save text inputs / textareas in long forms to localStorage
 *      every 2s; on page load offer to restore drafts after a crash /
 *      disconnect.
 *   5. Disable submit buttons for 2s after click to absorb double-clicks
 *      on slow networks.
 *   6. Trigger SW queue drain on `online` events and surface synced /
 *      queued writes as toasts.
 *
 * No build step, no framework. Vanilla so it stays auditable.
 */

(function () {
    'use strict';

    if (window.__electrotechResilienceLoaded) return;
    window.__electrotechResilienceLoaded = true;

    const CONFIG = window.__electrotechResilienceConfig || {};

    // Single safe-call wrapper used everywhere below. Anything that
    // throws here gets logged once and isolated; the rest of the
    // resilience layer keeps booting.
    function safe(label, fn) {
        try {
            fn();
        } catch (err) {
            try {
                console.warn('[resilience] ' + label + ' failed:', err);
            } catch (_) {
                // console may not exist in some embedded contexts
            }
        }
    }

    // ---------- Constants ----------

    const PING_URL = '/admin/ping';
    const PING_INTERVAL_MS = 60_000;
    const PING_TIMEOUT_MS = 8_000;
    const WEAK_RTT_MS = 1_500;
    const DRAFT_DEBOUNCE_MS = 2_000;
    const DRAFT_KEY_PREFIX = 'electrotech:draft:';
    const DRAFT_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000; // 7 days
    const IDEMP_HEADER = 'Idempotency-Key';
    const STATE_VERBS = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

    // ---------- Service worker registration ----------

    safe('sw-registration', function () {
        if (!CONFIG.serviceWorker) return;
        if (!('serviceWorker' in navigator)) return;

        // Wait until the page is idle so SW registration doesn't compete
        // with the initial Filament render for the slow link's bandwidth.
        window.addEventListener('load', function () {
            navigator.serviceWorker
                .register('/js/service-worker.js', { scope: '/' })
                .catch(function (err) {
                    console.warn('[resilience] SW registration failed:', err);
                });
        });

        navigator.serviceWorker.addEventListener('message', function (event) {
            safe('sw-message', function () {
                const data = event.data || {};
                if (data.type === 'write-queued') {
                    toast('Saved locally — will sync when connection returns.', 'warning');
                } else if (data.type === 'write-synced') {
                    toast('Pending change synced to server.', 'success');
                }
            });
        });
    });

    // ---------- Connection status pill ----------

    let connectionState = navigator.onLine ? 'good' : 'offline';
    let lastRttMs = null;
    let pill = null;

    function createPill() {
        const el = document.createElement('div');
        Object.assign(el.style, {
            position: 'fixed',
            bottom: '1rem',
            right: '1rem',
            zIndex: '99999',
            background: 'rgba(15,23,42,0.92)',
            color: '#f8fafc',
            padding: '0.4rem 0.75rem',
            borderRadius: '999px',
            fontSize: '0.8rem',
            fontFamily: 'Cairo, system-ui, sans-serif',
            display: 'flex',
            alignItems: 'center',
            gap: '0.5rem',
            boxShadow: '0 4px 12px rgba(0,0,0,0.25)',
            transition: 'opacity 0.2s ease',
            opacity: '0',
            pointerEvents: 'none',
        });
        const dot = document.createElement('span');
        Object.assign(dot.style, {
            width: '0.6rem',
            height: '0.6rem',
            borderRadius: '50%',
            background: '#10b981',
        });
        const text = document.createElement('span');
        text.textContent = 'Online';
        el.appendChild(dot);
        el.appendChild(text);
        return { el: el, dot: dot, text: text };
    }

    function renderPill() {
        if (!pill) return;
        const colors = {
            good: { bg: '#10b981', label: 'Online' },
            weak: { bg: '#f59e0b', label: 'Weak link (' + (lastRttMs || '—') + ' ms)' },
            offline: { bg: '#ef4444', label: 'Offline — changes saved locally' },
        };
        const c = colors[connectionState] || colors.good;
        pill.dot.style.background = c.bg;
        pill.text.textContent = c.label;
        pill.el.style.opacity = connectionState === 'good' ? '0' : '1';
        pill.el.style.pointerEvents = connectionState === 'good' ? 'none' : 'auto';
    }

    function attachPillIfNeeded() {
        if (!document.body) return;
        if (pill && pill.el.isConnected) return;
        if (!pill) pill = createPill();
        document.body.appendChild(pill.el);
    }

    // ---------- Heartbeat ----------

    async function ping() {
        const controller = new AbortController();
        const timer = setTimeout(function () { controller.abort(); }, PING_TIMEOUT_MS);
        const start = performance.now();
        try {
            const resp = await fetch(PING_URL, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
                cache: 'no-store',
            });
            if (!resp.ok) throw new Error('ping non-2xx');
            lastRttMs = Math.round(performance.now() - start);
            connectionState = lastRttMs > WEAK_RTT_MS ? 'weak' : 'good';
        } catch (err) {
            connectionState = 'offline';
        } finally {
            clearTimeout(timer);
            renderPill();
        }
    }

    safe('heartbeat-setup', function () {
        setInterval(function () { safe('ping-tick', ping); }, PING_INTERVAL_MS);

        window.addEventListener('online', function () {
            safe('online-event', function () {
                connectionState = 'good';
                renderPill();
                if (navigator.serviceWorker && navigator.serviceWorker.controller) {
                    navigator.serviceWorker.controller.postMessage({ type: 'drain' });
                }
                ping();
            });
        });

        window.addEventListener('offline', function () {
            safe('offline-event', function () {
                connectionState = 'offline';
                renderPill();
            });
        });

        document.addEventListener('visibilitychange', function () {
            safe('visibility-event', function () {
                if (document.visibilityState === 'visible') ping();
            });
        });
    });

    // ---------- Idempotency stamping (axios only) ----------
    //
    // We intentionally do NOT monkey-patch window.fetch. Wrapping fetch
    // caused Livewire 3's lazy-component bootstrap to break on certain
    // call shapes — and a broken bootstrap means Alpine never initialises
    // and the entire Filament UI stays hidden behind [x-cloak].
    //
    // Livewire 3 uses axios for its write requests; this interceptor
    // covers them and any application axios calls without touching the
    // global fetch primitive.

    function newIdempotencyKey() {
        try {
            if (window.crypto && window.crypto.randomUUID) {
                return window.crypto.randomUUID().replace(/-/g, '');
            }
        } catch (e) { /* fall through */ }
        return (
            Date.now().toString(36) +
            Math.random().toString(36).slice(2) +
            Math.random().toString(36).slice(2)
        ).slice(0, 32);
    }

    safe('axios-interceptor', function () {
        if (!window.axios || !window.axios.interceptors || !window.axios.interceptors.request) {
            return;
        }
        window.axios.interceptors.request.use(function (config) {
            try {
                const method = (config.method || 'get').toUpperCase();
                if (STATE_VERBS.has(method)) {
                    config.headers = config.headers || {};
                    if (!config.headers[IDEMP_HEADER]) {
                        config.headers[IDEMP_HEADER] = newIdempotencyKey();
                    }
                }
            } catch (e) {
                // never block a request because of our header stamping
            }
            return config;
        });
    });

    // ---------- Draft autosave ----------

    function fieldKey(name) {
        return DRAFT_KEY_PREFIX + location.pathname + ':' + name;
    }

    function purgeStaleDrafts() {
        const cutoff = Date.now() - DRAFT_MAX_AGE_MS;
        for (let i = localStorage.length - 1; i >= 0; i--) {
            const key = localStorage.key(i);
            if (!key || key.indexOf(DRAFT_KEY_PREFIX) !== 0) continue;
            try {
                const entry = JSON.parse(localStorage.getItem(key) || '{}');
                if (!entry.t || entry.t < cutoff) localStorage.removeItem(key);
            } catch (e) {
                localStorage.removeItem(key);
            }
        }
    }

    function autosaveSelector() {
        return [
            'form textarea[name]',
            'form input[type="text"][name]',
            'form input[type="email"][name]',
            'form input[type="number"][name]',
        ].join(', ');
    }

    function attachAutosave() {
        const fields = document.querySelectorAll(autosaveSelector());
        fields.forEach(function (field) {
            if (field.dataset.electrotechAutosave) return;
            field.dataset.electrotechAutosave = '1';

            let timer = null;
            field.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    safe('draft-save', function () {
                        const key = fieldKey(field.name);
                        localStorage.setItem(key, JSON.stringify({ v: field.value, t: Date.now() }));
                    });
                }, DRAFT_DEBOUNCE_MS);
            });
        });
    }

    function offerDraftRestore() {
        const candidates = document.querySelectorAll(autosaveSelector());
        const drafts = [];
        candidates.forEach(function (field) {
            const key = fieldKey(field.name);
            const raw = localStorage.getItem(key);
            if (!raw) return;
            try {
                const entry = JSON.parse(raw);
                if (entry && entry.v && entry.v !== field.value) {
                    drafts.push({ field: field, value: entry.v, key: key });
                }
            } catch (e) {
                localStorage.removeItem(key);
            }
        });

        if (drafts.length === 0) return;
        if (!document.body) return;

        const banner = document.createElement('div');
        Object.assign(banner.style, {
            position: 'fixed',
            top: '1rem',
            left: '50%',
            transform: 'translateX(-50%)',
            zIndex: '99999',
            background: '#1f2937',
            color: '#f8fafc',
            padding: '0.75rem 1rem',
            borderRadius: '0.5rem',
            fontSize: '0.875rem',
            fontFamily: 'Cairo, system-ui, sans-serif',
            display: 'flex',
            gap: '0.75rem',
            alignItems: 'center',
            boxShadow: '0 6px 24px rgba(0,0,0,0.3)',
        });
        banner.innerHTML =
            '<span>Unsaved draft found for ' + drafts.length + ' field(s) from a previous session.</span>' +
            '<button data-act="restore" style="background:#fb923c;color:#0f172a;border:0;padding:0.3rem 0.75rem;border-radius:0.3rem;font-weight:600;cursor:pointer;">Restore</button>' +
            '<button data-act="discard" style="background:transparent;color:#94a3b8;border:1px solid #475569;padding:0.3rem 0.75rem;border-radius:0.3rem;cursor:pointer;">Discard</button>';
        document.body.appendChild(banner);

        banner.querySelector('[data-act="restore"]').addEventListener('click', function () {
            drafts.forEach(function (d) {
                d.field.value = d.value;
                d.field.dispatchEvent(new Event('input', { bubbles: true }));
                d.field.dispatchEvent(new Event('change', { bubbles: true }));
            });
            banner.remove();
        });
        banner.querySelector('[data-act="discard"]').addEventListener('click', function () {
            drafts.forEach(function (d) { localStorage.removeItem(d.key); });
            banner.remove();
        });
    }

    // Wipe drafts on a successful form submit so we don't re-offer them
    // on the next visit.
    safe('submit-cleanup-listener', function () {
        document.addEventListener('submit', function (event) {
            safe('submit-cleanup', function () {
                const form = event.target;
                if (!form || form.tagName !== 'FORM') return;
                const fields = form.querySelectorAll('[name]');
                fields.forEach(function (field) { localStorage.removeItem(fieldKey(field.name)); });
            });
        }, true);
    });

    // ---------- Double-submit guard ----------

    safe('double-submit-guard', function () {
        document.addEventListener('click', function (event) {
            safe('double-submit-tick', function () {
                const target = event.target;
                if (!target || !target.closest) return;
                const btn = target.closest('button[type="submit"]');
                if (!btn || btn.disabled || btn.dataset.electrotechGuarded) return;
                btn.dataset.electrotechGuarded = '1';
                const originalDisabled = btn.disabled;
                btn.disabled = true;
                setTimeout(function () {
                    btn.disabled = originalDisabled;
                    delete btn.dataset.electrotechGuarded;
                }, 2000);
            });
        }, true);
    });

    // ---------- Toast helper ----------

    function toast(message, level) {
        if (!document.body) return;
        const el = document.createElement('div');
        const colors = {
            success: '#10b981',
            warning: '#f59e0b',
            danger: '#ef4444',
            info: '#3b82f6',
        };
        Object.assign(el.style, {
            position: 'fixed',
            top: '1rem',
            right: '1rem',
            zIndex: '99999',
            background: colors[level] || colors.info,
            color: '#fff',
            padding: '0.6rem 0.9rem',
            borderRadius: '0.4rem',
            fontFamily: 'Cairo, system-ui, sans-serif',
            fontSize: '0.85rem',
            boxShadow: '0 6px 18px rgba(0,0,0,0.25)',
            opacity: '0',
            transition: 'opacity 0.2s ease',
        });
        el.textContent = message;
        document.body.appendChild(el);
        requestAnimationFrame(function () { el.style.opacity = '1'; });
        setTimeout(function () {
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 250);
        }, 4000);
    }

    // ---------- Bootstrap on every navigation ----------
    //
    // Filament SPA mode (`->spa()`) swaps the page DOM via wire:navigate
    // without firing DOMContentLoaded. We re-run our DOM-touching setup
    // on every `livewire:navigated` event so autosave is wired on the
    // new page too.

    function bootstrapDom() {
        safe('purge-drafts', purgeStaleDrafts);
        safe('attach-pill', attachPillIfNeeded);
        safe('attach-autosave', attachAutosave);
        safe('offer-restore', offerDraftRestore);
        safe('render-pill', renderPill);
    }

    safe('bootstrap-listeners', function () {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bootstrapDom);
        } else {
            // Defer scripts can run after DOMContentLoaded in some edge
            // cases (e.g. inserted via SPA navigation); run immediately.
            bootstrapDom();
        }
        document.addEventListener('livewire:navigated', bootstrapDom);
        // First ping a moment after first paint to populate the pill.
        setTimeout(function () { safe('initial-ping', ping); }, 1500);
    });
})();
