/*
 * Conflicts view.
 *
 * When the server rejects or arbitrates away a client operation, the
 * Sync Engine writes a row to the local `conflicts` store and surfaces
 * a badge on the tab. This view lists the unresolved conflicts and
 * lets the operator acknowledge them.
 *
 * What this view does NOT do:
 *   - It does not re-submit a conflicted operation. By the time we get
 *     here, the server's authoritative state has overwritten the
 *     local optimistic record. If the operator wants to re-attempt
 *     the action, they should do so from the relevant tab (WO list,
 *     Inventory) using the now-current data. Re-pushing a known-stale
 *     payload would just create another conflict.
 *
 *   - It does not auto-resolve. Admin attention is sometimes the only
 *     correct outcome (e.g. insufficient_stock when the operator
 *     genuinely believes there should be enough stock).
 */

import { conflictList, conflictResolve } from '../db.js';
import { el, clear, toast, formatDate } from '../ui.js';
import { t } from '../i18n.js';

export async function render(root, engine) {
    clear(root);

    const conflicts = await conflictList();

    if (conflicts.length === 0) {
        root.appendChild(el('div', { class: 'empty' }, [
            el('h3', { text: t('conflicts.empty_title') }),
            el('p', { text: t('conflicts.empty_body') }),
        ]));
        return;
    }

    root.appendChild(el('p', {
        style: { color: '#64748b', fontSize: '14px', marginBottom: '12px' },
        text: t('conflicts.intro'),
    }));

    for (const c of conflicts) {
        root.appendChild(card(c, engine));
    }
}

function card(c, engine) {
    const reasonHint = t(`conflicts.reasons.${c.reason}`);
    // If the key wasn't found, t() returns the dotted key as fallback;
    // surface the raw error in that case so the operator at least sees
    // *something* meaningful.
    const hint = reasonHint.startsWith('conflicts.reasons.')
        ? (c.error || c.reason || '')
        : reasonHint;

    const wrap = el('div', { class: 'conflict-card' }, [
        el('h4', { text: `${c.model || 'record'} · ${c.reason}` }),
        el('div', { style: { fontSize: '13px' }, text: hint }),
        el('div', {
            style: { fontSize: '12px', color: '#92400e', marginTop: '6px' },
            text: t('conflicts.detected_at', { date: formatDate(c.created_at) }),
        }),
        el('details', { style: { marginTop: '8px' } }, [
            el('summary', { text: t('conflicts.details'), style: { cursor: 'pointer', fontSize: '13px' } }),
            el('pre', {
                class: 'conflict-diff',
                text: JSON.stringify({
                    [t('conflicts.labels.you_tried')]:    c.client_payload?.fields,
                    [t('conflicts.labels.base_version')]: c.client_payload?.base_version,
                    [t('conflicts.labels.server_version')]: c.server_version,
                    [t('conflicts.labels.server_state')]: c.server_state,
                    [t('conflicts.labels.error')]:        c.error,
                }, null, 2),
            }),
        ]),
        el('div', { style: { marginTop: '10px', display: 'flex', gap: '8px' } }, [
            el('button', {
                class: 'btn btn-primary',
                text: t('conflicts.acknowledge'),
                onclick: async () => {
                    await conflictResolve(c.id);
                    toast(t('conflicts.acknowledged_toast'), 'success');
                    const root = document.getElementById('view');
                    if (root) await render(root, engine);
                    document.dispatchEvent(new CustomEvent('conflicts:changed'));
                },
            }),
        ]),
    ]);
    return wrap;
}
