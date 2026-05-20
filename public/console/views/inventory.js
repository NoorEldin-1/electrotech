/*
 * Inventory view — read-mostly catalog with one write surface:
 * recording a stock movement (Out / Hold / Release / In) against an
 * item. This is the offline-capable counterpart to the Filament
 * InventoryTransaction resource.
 *
 * Movements are append-only (each is a new InventoryTransaction row)
 * so they queue cleanly. The server-side AppendOnlyResolver routes the
 * write through InventoryService, which arbitrates against the on-hand
 * level under the Redis lock — meaning two operators consuming the
 * last 10 units from the same item end up correctly: one succeeds,
 * one ends up in conflicts as `insufficient_stock`.
 */

import { tx, req } from '../db.js';
import { el, clear, toast, fmtNum } from '../ui.js';
import { t } from '../i18n.js';

export async function render(root, engine) {
    clear(root);

    const items = await tx(['items'], 'readonly', (s) => req(s.items.getAll()));
    const inventories = await tx(['inventories'], 'readonly', (s) => req(s.inventories.getAll()));
    const invByItem = new Map(inventories.map((i) => [i.item_id, i]));

    if (items.length === 0) {
        root.appendChild(el('div', { class: 'empty' }, [
            el('h3', { text: t('inventory.empty_title') }),
            el('p', { text: t('inventory.empty_body') }),
        ]));
        return;
    }

    const search = el('input', {
        type: 'search',
        placeholder: t('inventory.search_placeholder'),
        oninput: (e) => filter(e.target.value),
        style: { marginBottom: '12px', width: '100%' },
        class: 'field',
    });
    root.appendChild(search);

    const list = el('div', { class: 'list', id: 'inv-list' });
    root.appendChild(list);

    function filter(term) {
        const term_ = (term || '').toLowerCase().trim();
        clear(list);
        const filtered = !term_
            ? items
            : items.filter((i) =>
                  (i.name || '').toLowerCase().includes(term_) ||
                  (i.sku || '').toLowerCase().includes(term_)
              );

        if (filtered.length === 0) {
            list.appendChild(el('div', { class: 'empty', text: t('inventory.no_matches') }));
            return;
        }
        for (const item of filtered) {
            list.appendChild(itemCard(item, invByItem.get(item.id), engine));
        }
    }

    filter('');
}

function itemCard(item, inv, engine) {
    const onHand = inv ? Number.parseFloat(inv.on_hand_quantity || 0) : 0;
    const onHold = inv ? Number.parseFloat(inv.on_hold_quantity || 0) : 0;
    const avail  = onHand - onHold;
    const lowStock = avail < Number.parseFloat(item.minimum_stock || 0);

    return el('div', { class: 'card' }, [
        el('div', { class: 'card-header' }, [
            el('div', {}, [
                el('h3', { class: 'card-title', text: `${item.name}` }),
                el('div', {
                    class: 'card-subtitle',
                    text: `${t('inventory.sku_label', { sku: item.sku || '—' })} · ${item.unit || ''}`,
                }),
            ]),
            el('div', {}, [
                el('span', {
                    class: `pill ${lowStock ? 'pill-warning' : 'pill-info'}`,
                    text: t('inventory.available', { n: fmtNum(avail) }),
                }),
            ]),
        ]),
        el('div', { class: 'wo-meta' }, [
            t('inventory.on_hand', { n: fmtNum(onHand) }),
            ' · ',
            t('inventory.on_hold', { n: fmtNum(onHold) }),
            ' · ',
            t('inventory.min', { n: fmtNum(item.minimum_stock || 0) }),
        ]),
        el('div', { class: 'wo-actions', style: { marginTop: '10px' } }, [
            el('button', { class: 'btn btn-warning', text: t('inventory.actions.out'), onclick: () => promptMove(item, 'out', engine) }),
            el('button', { class: 'btn btn-primary', text: t('inventory.actions.in'),  onclick: () => promptMove(item, 'in',  engine) }),
            el('button', { class: 'btn btn-ghost',   text: t('inventory.actions.hold'),    onclick: () => promptMove(item, 'hold', engine) }),
            el('button', { class: 'btn btn-ghost',   text: t('inventory.actions.release'), onclick: () => promptMove(item, 'release', engine) }),
        ]),
    ]);
}

async function promptMove(item, type, engine) {
    const label = t(`inventory.actions.${type}`);
    const unit = item.unit || t('inventory.units_fallback');

    const qty = prompt(t('inventory.prompts.quantity', { label, name: item.name, unit }), '');
    if (qty === null || qty === '') return;
    const n = Number.parseFloat(qty);
    if (!Number.isFinite(n) || n <= 0) {
        toast(t('inventory.prompts.quantity_invalid'), 'danger');
        return;
    }
    const notes = prompt(t('inventory.prompts.notes'), '');
    if (notes === null) return; // cancelled

    try {
        await engine.queueAppend('inventory_transactions', {
            item_id: item.id,
            type,
            quantity: n,
            notes: notes || null,
        });
        toast(t('inventory.toasts.queued', {
            label, qty: fmtNum(n), unit, name: item.name,
        }), 'success');
        const root = document.getElementById('view');
        if (root) await render(root, engine);
    } catch (e) {
        toast(t('work_orders.toasts.queue_failed', { error: e.message }), 'danger');
    }
}
