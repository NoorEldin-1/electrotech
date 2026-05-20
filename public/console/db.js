/*
 * IndexedDB wrapper for the Operator Console.
 *
 * Schema overview:
 *
 *   meta              key/value config (token, device_id, user, cursors, last_sync_at)
 *   projects          { uuid (key), id, name, code, ... }
 *   items             { uuid (key), id, name, sku, inventory: {...}, ... }
 *   inventories       { uuid (key), id, item_id, on_hand_quantity, on_hold_quantity }
 *   boms              { uuid (key), id, project_id, version, status, ... }
 *   bom_items         { uuid (key), id, bom_id, item_id, quantity, ... }
 *   work_orders       { uuid (key), id, status, record_version, ..., assigned_to }
 *                       indexes: by-status, by-assigned-to, by-project
 *   inventory_transactions { uuid (key), item_id, type, quantity, performed_by, created_at }
 *                       indexes: by-item, by-created-at
 *   outbox            { id (autoinc), op_uuid, model, action, record_uuid, base_version, fields, attempts, last_error, created_at }
 *                       indexes: by-op-uuid (unique), by-created-at
 *   conflicts         { id (autoinc), conflict_uuid, model, record_uuid, reason, server_state, client_payload, created_at, resolved }
 *
 * Why no Dexie / no idb-keyval / no library:
 *   - The PWA shell needs to boot from a cold-cached state with zero
 *     module-load cost. A 30 kB dependency on top of the engine adds
 *     real latency on first paint over a 200 kbps link.
 *   - The schema is small and stable; the boilerplate is manageable.
 *
 * Transaction discipline:
 *   - Every write operation passes through `tx()` which wraps the
 *     callback in a single transaction so the operation is atomic.
 *   - The Sync Engine's pull/push handlers each call multiple tx()
 *     blocks rather than one giant block, so a slow handler doesn't
 *     hold a transaction open long enough to be terminated by the
 *     browser's IDB watchdog.
 */

const DB_NAME = 'electrotech-console';
const DB_VERSION = 1;

const STORES = [
    'meta',
    'projects',
    'items',
    'inventories',
    'boms',
    'bom_items',
    'work_orders',
    'inventory_transactions',
    'outbox',
    'conflicts',
];

let dbPromise = null;

export function db() {
    if (dbPromise) return dbPromise;

    dbPromise = new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);

        req.onupgradeneeded = (event) => {
            const idb = req.result;

            if (!idb.objectStoreNames.contains('meta')) {
                idb.createObjectStore('meta');
            }
            // Records keyed by uuid — the cross-system identity.
            const uuidKeyed = ['projects', 'items', 'inventories', 'boms', 'bom_items', 'work_orders', 'inventory_transactions'];
            uuidKeyed.forEach((name) => {
                if (!idb.objectStoreNames.contains(name)) {
                    const store = idb.createObjectStore(name, { keyPath: 'uuid' });
                    // Useful secondary indexes per store
                    if (name === 'work_orders') {
                        store.createIndex('by_status', 'status');
                        store.createIndex('by_assigned_to', 'assigned_to');
                        store.createIndex('by_project', 'project_id');
                    } else if (name === 'inventory_transactions') {
                        store.createIndex('by_item', 'item_id');
                        store.createIndex('by_created_at', 'created_at');
                    } else if (name === 'inventories') {
                        store.createIndex('by_item', 'item_id', { unique: true });
                    } else if (name === 'items') {
                        store.createIndex('by_sku', 'sku', { unique: true });
                    } else if (name === 'bom_items') {
                        store.createIndex('by_bom', 'bom_id');
                    }
                }
            });

            if (!idb.objectStoreNames.contains('outbox')) {
                const outbox = idb.createObjectStore('outbox', { keyPath: 'id', autoIncrement: true });
                outbox.createIndex('by_op_uuid', 'op_uuid', { unique: true });
                outbox.createIndex('by_created_at', 'created_at');
            }

            if (!idb.objectStoreNames.contains('conflicts')) {
                const conflicts = idb.createObjectStore('conflicts', { keyPath: 'id', autoIncrement: true });
                conflicts.createIndex('by_resolved', 'resolved');
                conflicts.createIndex('by_record', ['model', 'record_uuid']);
            }
        };

        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
        req.onblocked = () => reject(new Error('IndexedDB upgrade blocked. Close other tabs of this app and retry.'));
    });

    return dbPromise;
}

/**
 * Runs `fn` inside a transaction covering `stores` with `mode`. The
 * function receives an object whose properties are the object stores
 * — `tx.work_orders`, `tx.outbox`, etc. — so callers do not need to
 * remember the array-order incantation.
 *
 * The promise resolves to whatever `fn` returns once the transaction
 * actually completes (`tx.oncomplete`), not when the last request
 * resolves. This catches the classic IndexedDB footgun where a write
 * appears to succeed because its request fired, but the transaction
 * later aborts.
 */
export async function tx(stores, mode, fn) {
    const idb = await db();
    return new Promise((resolve, reject) => {
        const t = idb.transaction(stores, mode);
        const proxy = {};
        stores.forEach((s) => { proxy[s] = t.objectStore(s); });

        let result;
        Promise.resolve(fn(proxy, t)).then(
            (r) => { result = r; },
            (err) => { try { t.abort(); } catch (_) {} reject(err); }
        );

        t.oncomplete = () => resolve(result);
        t.onerror = () => reject(t.error);
        t.onabort = () => reject(t.error || new Error('Transaction aborted'));
    });
}

// Small request-to-promise helper for the cases where we don't want
// the full tx() ceremony.
export function req(r) {
    return new Promise((resolve, reject) => {
        r.onsuccess = () => resolve(r.result);
        r.onerror = () => reject(r.error);
    });
}

// ---------- Meta helpers (token, cursors, last sync, etc.) ----------

export async function metaGet(key) {
    return tx(['meta'], 'readonly', (s) => req(s.meta.get(key)));
}

export async function metaSet(key, value) {
    return tx(['meta'], 'readwrite', (s) => req(s.meta.put(value, key)));
}

export async function metaDelete(key) {
    return tx(['meta'], 'readwrite', (s) => req(s.meta.delete(key)));
}

// ---------- Outbox ----------

export async function outboxAdd(op) {
    const created_at = new Date().toISOString();
    return tx(['outbox'], 'readwrite', (s) =>
        req(s.outbox.add({ ...op, attempts: 0, last_error: null, created_at }))
    );
}

export async function outboxList() {
    return tx(['outbox'], 'readonly', (s) => req(s.outbox.getAll()));
}

export async function outboxCount() {
    return tx(['outbox'], 'readonly', (s) => req(s.outbox.count()));
}

export async function outboxRemove(id) {
    return tx(['outbox'], 'readwrite', (s) => req(s.outbox.delete(id)));
}

export async function outboxUpdate(entry) {
    return tx(['outbox'], 'readwrite', (s) => req(s.outbox.put(entry)));
}

// ---------- Generic store helpers ----------

export async function putMany(storeName, records) {
    if (!records || records.length === 0) return 0;
    return tx([storeName], 'readwrite', async (s) => {
        let n = 0;
        for (const r of records) {
            await req(s[storeName].put(r));
            n++;
        }
        return n;
    });
}

export async function getByUuid(storeName, uuid) {
    return tx([storeName], 'readonly', (s) => req(s[storeName].get(uuid)));
}

export async function getAll(storeName) {
    return tx([storeName], 'readonly', (s) => req(s[storeName].getAll()));
}

export async function removeMany(storeName, uuids) {
    if (!uuids || uuids.length === 0) return 0;
    return tx([storeName], 'readwrite', async (s) => {
        let n = 0;
        for (const u of uuids) {
            await req(s[storeName].delete(u));
            n++;
        }
        return n;
    });
}

// ---------- Conflicts ----------

export async function conflictAdd(conflict) {
    const created_at = new Date().toISOString();
    return tx(['conflicts'], 'readwrite', (s) =>
        req(s.conflicts.add({ ...conflict, created_at, resolved: 0 }))
    );
}

export async function conflictList() {
    return tx(['conflicts'], 'readonly', (s) => {
        const idx = s.conflicts.index('by_resolved');
        return req(idx.getAll(IDBKeyRange.only(0)));
    });
}

export async function conflictResolve(id) {
    return tx(['conflicts'], 'readwrite', async (s) => {
        const row = await req(s.conflicts.get(id));
        if (!row) return false;
        row.resolved = 1;
        row.resolved_at = new Date().toISOString();
        await req(s.conflicts.put(row));
        return true;
    });
}

// ---------- Reset / wipe ----------

export async function wipeAll() {
    return tx(STORES, 'readwrite', async (s) => {
        for (const name of STORES) {
            await req(s[name].clear());
        }
    });
}

// Generates a v4 UUID. crypto.randomUUID() is available in all
// modern browsers + service workers; this fallback is only there for
// extreme legacy environments we might still need to support.
export function uuidv4() {
    if (globalThis.crypto && typeof globalThis.crypto.randomUUID === 'function') {
        return globalThis.crypto.randomUUID();
    }
    const bytes = new Uint8Array(16);
    (globalThis.crypto || globalThis.msCrypto).getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
