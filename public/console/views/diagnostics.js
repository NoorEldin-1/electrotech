/*
 * Diagnostics view.
 *
 * For field troubleshooting: shows the current sync state, device id,
 * cursors, outbox depth, and storage usage. Read-only except for two
 * dangerous buttons:
 *
 *   "Force snapshot resync" — clears all data + cursors and pulls a
 *   fresh snapshot. Used when the user suspects local state is corrupt.
 *
 *   "Wipe & sign out" — drops the device token and all data. Used
 *   when handing the device to another operator.
 */

import { metaGet, outboxCount, getAll } from '../db.js';
import { el, clear, toast } from '../ui.js';
import { t } from '../i18n.js';

export async function render(root, engine) {
    clear(root);

    const [token, deviceId, user, cursors, lastSync, ob] = await Promise.all([
        metaGet('token'),
        metaGet('device_id'),
        metaGet('user'),
        metaGet('cursors'),
        metaGet('last_sync_at'),
        outboxCount(),
    ]);

    const counts = {};
    for (const m of ['projects', 'items', 'inventories', 'boms', 'bom_items', 'work_orders', 'inventory_transactions']) {
        counts[m] = (await getAll(m)).length;
    }

    let storage = 'unknown';
    if (navigator.storage && navigator.storage.estimate) {
        try {
            const e = await navigator.storage.estimate();
            const usedMB = ((e.usage || 0) / 1048576).toFixed(2);
            const quotaMB = ((e.quota || 0) / 1048576).toFixed(0);
            storage = `${usedMB} MB used / ${quotaMB} MB quota`;
        } catch {}
    }

    // Diagnostic payloads are intentionally non-translated keys — they
    // are dev-facing, copy-pasted into bug reports, and need to round-
    // trip between operators and engineers in different locales.
    const info = {
        sync_state:    engine.state(),
        connectivity:  engine.connectivity(),
        device_id:     deviceId,
        token_present: !!token,
        user:          user,
        last_sync_at:  lastSync,
        outbox_depth:  ob,
        cursors:       cursors,
        local_counts:  counts,
        storage_usage: storage,
        user_agent:    navigator.userAgent,
    };

    root.appendChild(el('div', { class: 'card' }, [
        el('h2', { class: 'card-title', text: t('diagnostics.title') }),
        el('pre', { class: 'diag', text: JSON.stringify(info, null, 2) }),
        el('div', { class: 'wo-actions', style: { marginTop: '12px' } }, [
            el('button', {
                class: 'btn btn-primary',
                text: t('diagnostics.actions.sync_now'),
                onclick: async () => {
                    try {
                        await engine.sync();
                        toast(t('diagnostics.toasts.sync_done'), 'success');
                    } catch (e) {
                        toast(t('diagnostics.toasts.sync_failed', { error: e.message }), 'danger');
                    }
                    await render(root, engine);
                },
            }),
            el('button', {
                class: 'btn btn-warning',
                text: t('diagnostics.actions.force_snapshot'),
                onclick: async () => {
                    if (!confirm(t('diagnostics.confirms.force_snapshot'))) return;
                    const { metaDelete, tx, req } = await import('../db.js');
                    await metaDelete('cursors');
                    for (const m of Object.keys(counts)) {
                        await tx([m], 'readwrite', (s) => req(s[m].clear()));
                    }
                    try {
                        await engine.sync();
                        toast(t('diagnostics.toasts.snapshot_done'), 'success');
                    } catch (e) {
                        toast(t('diagnostics.toasts.snapshot_failed', { error: e.message }), 'danger');
                    }
                    await render(root, engine);
                },
            }),
            el('button', {
                class: 'btn btn-danger',
                text: t('diagnostics.actions.sign_out'),
                onclick: async () => {
                    if (!confirm(t('diagnostics.confirms.sign_out'))) return;
                    await engine.signOut();
                    location.reload();
                },
            }),
        ]),
    ]));
}
