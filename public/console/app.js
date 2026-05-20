/*
 * Operator Console entry point.
 *
 * Boot order:
 *   1. Bootstrap the i18n layer FIRST so the rest of the app can call
 *      t() during initial render without the dictionary being empty.
 *   2. Register the Service Worker (idempotent — checks for an
 *      existing registration first).
 *   3. Instantiate the SyncEngine and bootstrap from IndexedDB.
 *   4. If unenrolled, show the enrolment dialog. Otherwise wire the
 *      UI and kick the first sync cycle.
 *   5. Set up the four tab views and connectivity/status indicators.
 *
 * The whole thing is one module to keep the initial import graph
 * small. The view modules are dynamic-imported so an operator who
 * never opens the Inventory tab pays zero bytes for it.
 */

import { SyncEngine } from './sync-engine.js';
import { metaGet, outboxCount, conflictList } from './db.js';
import { el, toast, formatDate } from './ui.js';
import { bootstrapLocale, setLocale, getLocale, t } from './i18n.js';

const engine = new SyncEngine();
const $ = (id) => document.getElementById(id);

const VIEWS = {
    'work-orders': () => import('./views/work-orders.js'),
    'inventory':   () => import('./views/inventory.js'),
    'conflicts':   () => import('./views/conflicts.js'),
    'diagnostics': () => import('./views/diagnostics.js'),
};

let currentRoute = 'work-orders';

async function main() {
    // i18n MUST come first — every subsequent UI step reads t().
    await bootstrapLocale();

    registerServiceWorker();

    const state = await engine.bootstrap();

    wireStatusIndicators();
    wireTabs();
    wireGlobalActions();
    wireLanguageSwitcher();

    if (!state.enrolled) {
        await showEnrollmentDialog();
    } else {
        $('user-name').textContent = state.user?.name || state.user?.email || '';
    }

    await renderRoute(currentRoute);
    await refreshOutboxBadge();
    await refreshConflictBadge();
    await refreshLastSync();
    refreshLanguageSwitcherLabel();

    // Re-render the active view AND refresh dynamic status pills when
    // the locale changes. data-i18n attributes are handled by the
    // i18n module itself; everything that's interpolated lives here.
    window.addEventListener('i18n:change', async () => {
        refreshConnectivityPill(engine.connectivity());
        refreshSyncStatePill(engine.state());
        await refreshOutboxBadge();
        await refreshLastSync();
        refreshLanguageSwitcherLabel();
        await renderRoute(currentRoute);
    });
}

function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return;
    window.addEventListener('load', () => {
        navigator.serviceWorker
            .register('/console/sw.js', { scope: '/console/' })
            .catch((err) => console.warn('console-sw register failed', err));
    });
}

function refreshConnectivityPill(state) {
    const conn = $('connectivity');
    conn.className = `pill pill-${state}`;
    conn.textContent = t(`connectivity.${state}`);
}

function refreshSyncStatePill(state) {
    const sync = $('sync-state');
    sync.className = `pill pill-${state === 'syncing' ? 'syncing' : 'idle'}`;
    sync.textContent = t(`sync_state.${state}`);
}

function wireStatusIndicators() {
    refreshConnectivityPill(engine.connectivity());
    refreshSyncStatePill(engine.state());

    engine.addEventListener('connectivity', (e) => refreshConnectivityPill(e.detail.state));
    engine.addEventListener('state:change', (e) => refreshSyncStatePill(e.detail.state));

    engine.addEventListener('records:updated', () => {
        // Re-render the active view so new records appear immediately.
        renderRoute(currentRoute).catch(() => {});
    });

    engine.addEventListener('sync:success', () => {
        refreshLastSync().catch(() => {});
    });

    engine.addEventListener('sync:error', (e) => {
        // Quiet — the connectivity pill already communicates the failure.
        if (!engine.isEnrolled()) return;
        console.debug('sync error', e.detail);
    });

    engine.addEventListener('outbox:changed', () => {
        refreshOutboxBadge().catch(() => {});
    });

    engine.addEventListener('conflict:added', () => {
        refreshConflictBadge().catch(() => {});
    });

    document.addEventListener('conflicts:changed', () => {
        refreshConflictBadge().catch(() => {});
    });
}

function wireTabs() {
    for (const btn of document.querySelectorAll('.tab-btn')) {
        btn.addEventListener('click', async () => {
            const route = btn.dataset.route;
            if (route === currentRoute) return;
            currentRoute = route;
            for (const b of document.querySelectorAll('.tab-btn')) {
                b.classList.toggle('is-active', b.dataset.route === route);
            }
            await renderRoute(route);
        });
    }
}

function wireGlobalActions() {
    $('sync-now').addEventListener('click', async () => {
        try {
            await engine.sync();
            toast(t('toasts.sync_requested'), 'info', 1500);
        } catch (e) {
            toast(t('toasts.sync_failed', { error: e.message }), 'danger');
        }
    });

    $('sign-out').addEventListener('click', async () => {
        if (!confirm(t('confirms.sign_out'))) return;
        await engine.signOut();
        location.reload();
    });
}

function wireLanguageSwitcher() {
    $('lang-switch').addEventListener('click', async () => {
        const next = getLocale() === 'ar' ? 'en' : 'ar';
        await setLocale(next);
    });
}

function refreshLanguageSwitcherLabel() {
    // The button shows the language it would switch *to*, not the
    // current one — so an operator reading "العربية" on an English
    // page understands clicking will give them Arabic. This mirrors
    // the Wikipedia / iOS Settings convention.
    $('lang-switch').textContent = t('language.switch');
}

async function showEnrollmentDialog() {
    const dialog = $('enroll-dialog');
    const form = $('enroll-form');
    const err = $('enroll-error');

    dialog.showModal();

    return new Promise((resolve) => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            err.hidden = true;
            const name = form.elements.device_name?.value?.trim() || null;
            try {
                const result = await engine.enroll({ deviceName: name });
                $('user-name').textContent = result.user?.name || result.user?.email || '';
                dialog.close();
                toast(t('toasts.enrolled'), 'success');
                resolve();
            } catch (e2) {
                err.textContent = e2.message + '. ' + t('enroll.login_hint');
                err.hidden = false;
            }
        });
    });
}

async function renderRoute(route) {
    const view = $('view');
    const loader = VIEWS[route];
    if (!loader) {
        view.innerHTML = '';
        view.appendChild(el('div', { class: 'empty' }, [el('h3', { text: route })]));
        return;
    }
    try {
        const mod = await loader();
        await mod.render(view, engine);
    } catch (e) {
        view.innerHTML = '';
        const errCard = el('div', { class: 'card' }, [
            el('h3', { text: t('boot.failed_title') }),
            el('pre', { class: 'diag', text: String(e && e.stack || e) }),
        ]);
        view.appendChild(errCard);
    }
}

async function refreshOutboxBadge() {
    const n = await outboxCount();
    const pill = $('outbox-count');
    pill.textContent = t('outbox_count', { n });
    pill.className = n > 0 ? 'pill pill-warning' : 'pill pill-info';
}

async function refreshConflictBadge() {
    const list = await conflictList();
    const badge = $('conflict-badge');
    if (list.length === 0) {
        badge.hidden = true;
    } else {
        badge.hidden = false;
        badge.textContent = String(list.length);
    }
}

async function refreshLastSync() {
    const ts = await metaGet('last_sync_at');
    $('last-sync').textContent = ts ? formatDate(ts) : t('footer.never');
}

main().catch((e) => {
    document.body.innerHTML = `<div style="font-family:sans-serif;padding:24px;color:#dc2626"><h2>${t('boot.failed_title')}</h2><pre>${String(e && e.stack || e)}</pre></div>`;
});
