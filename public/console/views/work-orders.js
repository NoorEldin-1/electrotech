/*
 * Work Orders view — the centre of gravity of the operator console.
 *
 * Lists the WOs the operator has access to (the server scopes the
 * pull to assigned_to + created_by + QA review queue), grouped by
 * status. Tapping a card opens a detail panel with action buttons
 * appropriate to the current state:
 *
 *   Pending     → [Start]
 *   InProgress  → [Submit for QA]   (asks for produced + waste quantities)
 *   QaReview    → [Approve QA] [Complete]
 *
 * Every action calls engine.queueTransition(); the UI updates
 * optimistically and the sync engine reconciles when the server
 * replies. Rows in optimistic-pending state are visibly marked.
 */

import { tx, req } from '../db.js';
import { el, clear, toast, fmtNum } from '../ui.js';
import { t } from '../i18n.js';

const STATUS_ORDER = ['in_progress', 'qa_review', 'pending', 'completed', 'cancelled'];

export async function render(root, engine) {
    clear(root);

    const woList = await tx(['work_orders'], 'readonly', (s) => req(s.work_orders.getAll()));

    if (woList.length === 0) {
        root.appendChild(el('div', { class: 'empty' }, [
            el('h3', { text: t('work_orders.empty_title') }),
            el('p', { text: t('work_orders.empty_body') }),
        ]));
        return;
    }

    // Group + sort by status
    const groups = STATUS_ORDER.map((status) => ({
        status,
        items: woList
            .filter((w) => w.status === status)
            .sort((a, b) => (a.planned_start_date || '').localeCompare(b.planned_start_date || '')),
    })).filter((g) => g.items.length > 0);

    for (const group of groups) {
        const headerText = t('work_orders.group_header', {
            label: t(`work_orders.statuses.${group.status}`),
            count: group.items.length,
        });
        const section = el('section', { class: 'card' }, [
            el('div', { class: 'card-header' }, [
                el('h2', { class: 'card-title', text: headerText }),
            ]),
            el('div', { class: 'list', dataset: { status: group.status } }),
        ]);

        const list = section.querySelector('.list');
        for (const wo of group.items) {
            list.appendChild(renderCard(wo, engine));
        }
        root.appendChild(section);
    }
}

function renderCard(wo, engine) {
    const meta = [];
    if (wo.wo_number) meta.push(t('work_orders.meta.number', { n: wo.wo_number }));
    if (wo.planned_quantity) meta.push(t('work_orders.meta.planned', { n: fmtNum(wo.planned_quantity) }));
    if (wo.produced_quantity) meta.push(t('work_orders.meta.produced', { n: fmtNum(wo.produced_quantity) }));
    if (wo._pending) meta.push(t('work_orders.meta.pending_sync'));

    const card = el('div', {
        class: 'wo-card',
        dataset: { status: wo.status, uuid: wo.uuid },
    }, [
        el('div', {}, [
            el('div', { class: 'wo-title', text: wo.title || `WO ${wo.wo_number || ''}` }),
            el('div', { class: 'wo-meta', text: meta.join(' · ') }),
        ]),
        el('div', { class: 'wo-actions' }, actionsFor(wo, engine)),
    ]);

    return card;
}

function actionsFor(wo, engine) {
    if (wo._pending) {
        return [el('span', { class: 'pill pill-syncing', text: t('work_orders.actions.syncing') })];
    }

    switch (wo.status) {
        case 'pending':
            return [el('button', {
                class: 'btn btn-primary',
                onclick: () => startWo(wo, engine),
                text: t('work_orders.actions.start'),
            })];
        case 'in_progress':
            return [el('button', {
                class: 'btn btn-warning',
                onclick: () => submitQa(wo, engine),
                text: t('work_orders.actions.submit_qa'),
            })];
        case 'qa_review':
            return [
                el('button', {
                    class: 'btn btn-success',
                    onclick: () => completeWo(wo, engine),
                    text: t('work_orders.actions.complete'),
                }),
            ];
        default:
            return [];
    }
}

async function startWo(wo, engine) {
    if (!confirm(t('work_orders.prompts.start_confirm', { title: wo.title }))) return;
    try {
        await engine.queueTransition('work_orders', wo.uuid, 'in_progress');
        toast(t('work_orders.toasts.started', { wo_number: wo.wo_number || '' }), 'success');
        const root = document.getElementById('view');
        if (root) await render(root, engine);
    } catch (e) {
        toast(t('work_orders.toasts.queue_failed', { error: e.message }), 'danger');
    }
}

async function submitQa(wo, engine) {
    const produced = prompt(
        t('work_orders.prompts.produced_quantity', {
            wo_number: wo.wo_number || '',
            planned: fmtNum(wo.planned_quantity),
        }),
        wo.planned_quantity || '0'
    );
    if (produced === null) return;
    const waste = prompt(t('work_orders.prompts.waste_quantity'), '0');
    if (waste === null) return;

    const p = Number.parseFloat(produced);
    const w = Number.parseFloat(waste);
    if (!Number.isFinite(p) || p < 0 || !Number.isFinite(w) || w < 0) {
        toast(t('work_orders.prompts.quantity_invalid'), 'danger');
        return;
    }

    try {
        await engine.queueTransition('work_orders', wo.uuid, 'qa_review', {
            produced_quantity: p,
            waste_quantity: w,
        });
        toast(t('work_orders.toasts.submitted'), 'success');
        const root = document.getElementById('view');
        if (root) await render(root, engine);
    } catch (e) {
        toast(t('work_orders.toasts.queue_failed', { error: e.message }), 'danger');
    }
}

async function completeWo(wo, engine) {
    const notes = prompt(t('work_orders.prompts.qa_notes', { wo_number: wo.wo_number || '' }), '');
    if (notes === null) return;

    try {
        await engine.queueTransition('work_orders', wo.uuid, 'completed', { qa_notes: notes });
        toast(t('work_orders.toasts.completed'), 'success');
        const root = document.getElementById('view');
        if (root) await render(root, engine);
    } catch (e) {
        toast(t('work_orders.toasts.queue_failed', { error: e.message }), 'danger');
    }
}
