/*
 * English dictionary. Keys are dotted paths consumed via t() in i18n.js.
 *
 * Conventions:
 *   - Toast/prompt strings end with a period.
 *   - {placeholders} are interpolated at runtime.
 *   - Status labels mirror the server-side enum keys
 *     (e.g. work_orders.statuses.in_progress) so we never lose the
 *     round-trip between value and label.
 */
export default {
    language: {
        name: 'English',
        switch: 'العربية',
    },

    title: 'Operator Console',

    enroll: {
        heading: 'Enrol This Device',
        help: 'This tablet needs a one-time setup to work offline. Make sure you are signed in to the admin panel in another tab, then click Enrol.',
        device_name_label: 'Device name (optional)',
        device_name_placeholder: 'Floor tablet 03',
        submit: 'Enrol',
        login_hint: 'Make sure you are logged in to /admin in another tab.',
    },

    topbar: {
        connectivity_title: 'Connectivity',
        sync_state_title: 'Sync state',
        outbox_title: 'Pending operations',
        sync_now: 'Force sync now',
        sign_out: 'Sign out of this device',
        language_switch: 'Switch language',
    },

    tabs: {
        work_orders: 'Work Orders',
        inventory: 'Inventory',
        conflicts: 'Conflicts',
        diagnostics: 'Diagnostics',
    },

    connectivity: {
        online: 'online',
        weak: 'weak link',
        offline: 'offline',
        unknown: '…',
    },

    sync_state: {
        idle: 'idle',
        syncing: 'syncing',
        error: 'error',
        unenrolled: 'unenrolled',
    },

    outbox_count: '{n} queued',

    footer: {
        prefix: 'Offline-first build · all writes captured locally · last sync: ',
        never: 'never',
    },

    confirms: {
        sign_out: 'Sign out of this device? Any unsynced changes will be lost.',
    },

    toasts: {
        sync_requested: 'Sync requested.',
        enrolled: 'Device enrolled. Initial sync running…',
        sync_failed: 'Sync failed: {error}',
    },

    boot: {
        failed_title: 'Operator Console failed to start',
    },

    work_orders: {
        statuses: {
            pending: 'Pending',
            in_progress: 'In Progress',
            qa_review: 'QA Review',
            completed: 'Completed',
            cancelled: 'Cancelled',
        },
        group_header: '{label} ({count})',
        empty_title: 'No work orders',
        empty_body: 'Once your supervisor assigns work, it will appear here — online or offline.',
        meta: {
            number: '#{n}',
            planned: 'Planned: {n}',
            produced: 'Produced: {n}',
            pending_sync: '⏳ pending sync',
        },
        actions: {
            start: 'Start',
            submit_qa: 'Submit for QA',
            complete: 'QA Approve & Complete',
            syncing: 'syncing…',
        },
        prompts: {
            start_confirm: 'Start work on "{title}"?',
            produced_quantity: 'Produced quantity for WO {wo_number} (planned {planned}):',
            waste_quantity: 'Waste quantity:',
            qa_notes: 'QA notes for WO {wo_number} (optional):',
            quantity_invalid: 'Quantities must be non-negative numbers.',
        },
        toasts: {
            started: 'WO {wo_number} started (queued for sync).',
            submitted: 'Submitted for QA (queued for sync).',
            completed: 'QA approved & completed (queued for sync).',
            queue_failed: 'Failed to queue: {error}',
        },
    },

    inventory: {
        search_placeholder: 'Search by name or SKU…',
        empty_title: 'No items yet',
        empty_body: 'Item catalog will appear here after the next sync.',
        no_matches: 'No matches.',
        sku_label: 'SKU: {sku}',
        available: '{n} available',
        on_hand: 'On hand: {n}',
        on_hold: 'On hold: {n}',
        min: 'Min: {n}',
        units_fallback: 'units',
        actions: {
            out: 'Consume',
            in: 'Receive',
            hold: 'Hold',
            release: 'Release',
        },
        prompts: {
            quantity: '{label} how much of {name}? (in {unit})',
            notes: 'Notes (optional):',
            quantity_invalid: 'Quantity must be a positive number.',
        },
        toasts: {
            queued: '{label} {qty} {unit} of {name} (queued).',
        },
    },

    conflicts: {
        empty_title: 'No conflicts',
        empty_body: 'Everything you have done so far has synced cleanly.',
        intro: 'The server arbitrated away the operations below. Review and acknowledge them once you have either retried (with current data) or escalated to a supervisor.',
        detected_at: 'at {date}',
        details: 'Details',
        acknowledge: 'Acknowledge',
        acknowledged_toast: 'Conflict acknowledged.',
        labels: {
            you_tried: 'You tried',
            base_version: 'Base version',
            server_version: 'Server version',
            server_state: 'Server state',
            error: 'Error',
        },
        reasons: {
            version_stale: 'Another user updated this record while you were offline. Your local edit was discarded in favour of the latest server state.',
            illegal_transition: 'The server refused this state change — usually because the work order has already moved past this step.',
            insufficient_stock: 'The warehouse does not have enough stock to fulfil this consumption.',
            validation_failed: 'The server rejected the payload as malformed.',
            fk_missing: 'A referenced record (item, work order, …) no longer exists.',
            push_rejected: 'The server returned an HTTP error for the push.',
            tombstoned: 'The record was deleted on the server.',
        },
    },

    diagnostics: {
        title: 'Sync diagnostics',
        actions: {
            sync_now: 'Sync now',
            force_snapshot: 'Force snapshot resync',
            sign_out: 'Wipe & sign out',
        },
        confirms: {
            force_snapshot: 'This will discard local data and reload everything from the server. Continue?',
            sign_out: 'This will erase the device token and all local data. The device will need to be re-enrolled. Continue?',
        },
        toasts: {
            sync_done: 'Sync completed.',
            sync_failed: 'Sync failed: {error}',
            snapshot_done: 'Snapshot complete.',
            snapshot_failed: 'Snapshot failed: {error}',
        },
    },
};
