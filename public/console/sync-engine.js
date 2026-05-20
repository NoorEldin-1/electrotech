/*
 * Operator Console Sync Engine.
 *
 * Responsibilities:
 *   - Pull deltas from the server and merge them into IndexedDB.
 *   - Drain the outbox to /sync/push.
 *   - Track connectivity state (online / weak / offline).
 *   - Surface conflicts and tombstones to the UI.
 *
 * Invariants:
 *
 *   I1. ORDER. The sync cycle always runs PULL → PUSH → PULL. The first
 *       pull ensures the client has the latest base_versions before we
 *       try to push; the trailing pull picks up any echoes of our own
 *       writes (and conflicts that the server-side resolver may have
 *       created).
 *
 *   I2. SERIALISATION. Only one sync cycle runs at a time. Concurrent
 *       triggers (online event + interval timer + manual button) are
 *       coalesced via the syncPromise gate.
 *
 *   I3. NO PARTIAL APPLIED STATE. A pull delta is applied to IndexedDB
 *       inside a single transaction per store, so a mid-pull failure
 *       does NOT leave half a model store updated. The cursor is
 *       persisted last; if persisting fails, the next pull replays
 *       the same delta — that's OK because deltas are idempotent
 *       (uuid keys, put-not-add).
 *
 *   I4. OUTBOX FIFO. Operations drain in insertion order. A failed
 *       op blocks its successors only if its failure is `5xx`;
 *       deterministic rejections (4xx / conflicted) get a conflict
 *       row created in IDB and are removed from the outbox so they
 *       don't head-block the queue.
 *
 *   I5. NO CACHED CSRF / SESSION. Every API call carries the bearer
 *       token in the Authorization header. There is no cookie path,
 *       no session, no CSRF; the server is configured to skip CSRF
 *       on /sync/*.
 *
 * The engine emits events the UI listens to:
 *   'state:change'      → {state: 'idle'|'syncing'|'error', ...}
 *   'connectivity'      → {state: 'online'|'weak'|'offline', rtt_ms?: number}
 *   'records:updated'   → {model, count}
 *   'conflict:added'    → {conflict}
 *   'outbox:changed'    → {count}
 */

import {
    db, tx, req,
    metaGet, metaSet, metaDelete,
    outboxAdd, outboxList, outboxRemove, outboxUpdate, outboxCount,
    putMany, removeMany,
    conflictAdd,
    uuidv4,
} from './db.js';

const SYNC_INTERVAL_MS = 30_000;   // every 30s when online
const WEAK_RTT_MS = 1500;          // > 1.5 s = weak link pill
const OFFLINE_AFTER_FAILS = 3;     // 3 consecutive failures = offline

export class SyncEngine extends EventTarget {
    constructor() {
        super();
        this._syncPromise = null;
        this._timer = null;
        this._consecutiveFails = 0;
        this._state = 'idle';
        this._connectivity = 'unknown';
        this._token = null;
        this._deviceId = null;
        this._user = null;
    }

    /**
     * Initialise from persisted state. If no token exists, the engine
     * stays parked in 'unenrolled' state until enroll() is called.
     */
    async bootstrap() {
        this._token = (await metaGet('token')) || null;
        this._deviceId = (await metaGet('device_id')) || null;
        this._user = (await metaGet('user')) || null;

        if (!this._deviceId) {
            this._deviceId = uuidv4();
            await metaSet('device_id', this._deviceId);
        }

        if (!this._token) {
            this._setState('unenrolled');
            return { enrolled: false };
        }

        this._setState('idle');
        this._installListeners();
        // First sync soon (after current task), not synchronously, so
        // the UI can paint first.
        setTimeout(() => this.sync().catch(() => {}), 100);
        return { enrolled: true, user: this._user };
    }

    /**
     * Enrol the device against the running web session. Caller must
     * have a logged-in /admin session in the same browser. Returns
     * the bootstrap state.
     */
    async enroll({ deviceName } = {}) {
        const resp = await fetch('/sync/enroll', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ device_id: this._deviceId, device_name: deviceName }),
        });

        if (!resp.ok) {
            const text = await resp.text();
            throw new Error(`Enrolment failed (${resp.status}): ${text}`);
        }

        const data = await resp.json();
        this._token = data.token;
        this._user  = data.user;

        await metaSet('token', data.token);
        await metaSet('user',  data.user);

        this._setState('idle');
        this._installListeners();
        return data;
    }

    /**
     * Wipe the device. Drops the token, the local data, and any
     * outbox entries — the operator should normally drain first, but
     * forcing a clean reset is sometimes the only way out of a stuck
     * state (a conflict that requires admin attention, for instance).
     */
    async signOut() {
        try { await fetch('/sync/heartbeat', { headers: this._authHeaders() }); } catch {}
        const { wipeAll } = await import('./db.js');
        await wipeAll();
        this._token = null;
        this._user = null;
        this._setState('unenrolled');
    }

    isEnrolled() { return !!this._token; }
    state()        { return this._state; }
    connectivity() { return this._connectivity; }
    user()         { return this._user; }
    deviceId()     { return this._deviceId; }

    // -----------------------------------------------------------------
    // Outbox API used by the UI to queue writes.
    // -----------------------------------------------------------------

    /**
     * Queue an upsert operation. `fields` should be the partial state
     * the client wants to apply; the engine will set op_uuid, the
     * base_version (from the current local record), and stamp the
     * client_updated_at.
     */
    async queueUpsert(model, recordUuid, fields, opts = {}) {
        const local = recordUuid ? await tx([model], 'readonly', (s) => req(s[model].get(recordUuid))) : null;
        return this._queue({
            op_uuid: uuidv4(),
            model,
            action: 'upsert',
            record_uuid: recordUuid,
            base_version: local ? local.record_version : null,
            client_updated_at: new Date().toISOString(),
            fields,
            _optimistic: opts.optimistic !== false,
        });
    }

    /**
     * Queue a state-machine transition (currently used for WorkOrder).
     * The fields object carries the target status + any side-effect
     * actuals (produced_quantity, qa_notes, etc.).
     */
    async queueTransition(model, recordUuid, targetStatus, extraFields = {}) {
        const local = await tx([model], 'readonly', (s) => req(s[model].get(recordUuid)));
        return this._queue({
            op_uuid: uuidv4(),
            model,
            action: 'transition',
            record_uuid: recordUuid,
            base_version: local ? local.record_version : null,
            client_updated_at: new Date().toISOString(),
            fields: { status: targetStatus, ...extraFields },
            _optimistic: true,
        });
    }

    /**
     * Queue an append-only record (e.g. inventory transaction).
     * `recordUuid` is client-minted because we are creating fresh.
     */
    async queueAppend(model, fields) {
        const recordUuid = uuidv4();
        return this._queue({
            op_uuid: uuidv4(),
            model,
            action: 'upsert',
            record_uuid: recordUuid,
            base_version: null,
            client_updated_at: new Date().toISOString(),
            fields,
            _optimistic: true,
            _new_uuid: recordUuid,
        });
    }

    async _queue(op) {
        // Optimistic local apply: write the change to IndexedDB so the
        // UI updates immediately, BEFORE the network sync. This is the
        // whole point of offline-first — the user does not wait for
        // the server.
        if (op._optimistic) {
            await this._applyOptimistic(op);
        }

        const { _optimistic, _new_uuid, ...persistable } = op;
        await outboxAdd(persistable);
        this._emitOutbox();

        // Kick a sync attempt if we're online — but don't block the
        // caller on it. The interval timer will pick it up otherwise.
        if (navigator.onLine) {
            queueMicrotask(() => this.sync().catch(() => {}));
        }
        return op.op_uuid;
    }

    async _applyOptimistic(op) {
        const store = op.model;
        if (!store) return;

        if (op.action === 'upsert' || op.action === 'transition') {
            const existing = op.record_uuid
                ? await tx([store], 'readonly', (s) => req(s[store].get(op.record_uuid)))
                : null;
            const updated = {
                ...(existing || {}),
                uuid: op.record_uuid || op._new_uuid,
                ...op.fields,
                // Mark the row as pending so the UI can dim/disable
                // further actions on it until the server confirms.
                _pending: true,
            };
            await tx([store], 'readwrite', (s) => req(s[store].put(updated)));
            this.dispatchEvent(new CustomEvent('records:updated', { detail: { model: store, count: 1 } }));
        }
    }

    // -----------------------------------------------------------------
    // The sync cycle.
    // -----------------------------------------------------------------

    /**
     * Run a full pull → push → pull cycle. Coalesces concurrent
     * callers onto the same promise so we never run two cycles at
     * once.
     */
    async sync() {
        if (this._syncPromise) return this._syncPromise;
        if (!this._token) return Promise.resolve({ skipped: 'unenrolled' });

        this._syncPromise = (async () => {
            this._setState('syncing');
            const startedAt = performance.now();
            try {
                await this._pull();
                await this._drainOutbox();
                await this._pull(); // pick up server-side echoes / conflicts
                this._consecutiveFails = 0;
                this._setConnectivity('online', performance.now() - startedAt);
                await metaSet('last_sync_at', new Date().toISOString());
                this.dispatchEvent(new CustomEvent('sync:success'));
            } catch (err) {
                this._consecutiveFails++;
                if (this._consecutiveFails >= OFFLINE_AFTER_FAILS) {
                    this._setConnectivity('offline');
                } else {
                    this._setConnectivity('weak');
                }
                this.dispatchEvent(new CustomEvent('sync:error', { detail: { error: err.message } }));
                // Re-throw so callers can react if they want
                throw err;
            } finally {
                this._setState('idle');
                this._syncPromise = null;
            }
        })();
        return this._syncPromise;
    }

    async _pull() {
        const cursors = (await metaGet('cursors')) || {};
        const resp = await this._api('/sync/pull', {
            method: 'POST',
            body: JSON.stringify({ cursors }),
        });
        if (!resp.ok) throw new Error(`pull ${resp.status}`);
        const data = await resp.json();

        const updatedCursors = { ...cursors };

        for (const delta of data.deltas || []) {
            const model = delta.model;
            if (!model) continue;

            // Filter out optimistic-pending rows from being clobbered
            // by a stale server response. The trailing _pending flag is
            // dropped when the server confirms via outbox drain — until
            // then, the local state is the truth.
            const localPending = new Set();
            if (delta.records.length > 0) {
                const localAll = await tx([model], 'readonly', (s) => req(s[model].getAll()));
                for (const row of localAll) {
                    if (row._pending) localPending.add(row.uuid);
                }
            }
            const incoming = delta.records.filter((r) => !localPending.has(r.uuid));

            if (incoming.length > 0) {
                await putMany(model, incoming);
                this.dispatchEvent(new CustomEvent('records:updated', {
                    detail: { model, count: incoming.length },
                }));
            }

            if (delta.tombstones && delta.tombstones.length > 0) {
                // Tombstones may carry `_tombstone_id` (internal cursor
                // metadata) which we don't need locally; only the uuids
                // matter for purging IDB.
                await removeMany(model, delta.tombstones.map((t) => t.uuid));
                this.dispatchEvent(new CustomEvent('records:updated', {
                    detail: { model, count: delta.tombstones.length, kind: 'deleted' },
                }));
            }

            if (delta.next_cursor) {
                updatedCursors[model] = delta.next_cursor;
            }

            // If the server insists we need a fresh snapshot (we lagged
            // too far behind), wipe the model store and re-sync from
            // zero by deleting the cursor. The next pull will pick it
            // up as an initial sync.
            if (delta.force_snapshot) {
                await tx([model], 'readwrite', (s) => req(s[model].clear()));
                delete updatedCursors[model];
                this.dispatchEvent(new CustomEvent('records:updated', {
                    detail: { model, count: 0, kind: 'snapshot_required' },
                }));
            }
        }

        await metaSet('cursors', updatedCursors);
    }

    async _drainOutbox() {
        const ops = await outboxList();
        if (ops.length === 0) return;

        // Send in chunks of 50 — well under the server's max of 200,
        // but small enough that a chunk can complete on a 200 kbps
        // link before the client gives up.
        const chunkSize = 50;
        for (let i = 0; i < ops.length; i += chunkSize) {
            const chunk = ops.slice(i, i + chunkSize);
            const payload = { operations: chunk.map((o) => this._stripInternal(o)) };

            let resp;
            try {
                resp = await this._api('/sync/push', { method: 'POST', body: JSON.stringify(payload) });
            } catch (e) {
                // Network failure mid-drain: stop, leave the rest for
                // the next attempt. Successful prior chunks remain
                // removed because we delete-per-op below.
                throw e;
            }

            if (!resp.ok) {
                if (resp.status >= 500) {
                    // Server transient — bail out, will retry next cycle.
                    throw new Error(`push ${resp.status}`);
                }
                // 4xx — payload malformed. Mark all chunk ops as
                // permanently failed (move to conflicts) and drop them
                // from the outbox so they don't loop forever.
                const body = await resp.json().catch(() => ({}));
                for (const op of chunk) {
                    await conflictAdd({
                        model: op.model,
                        record_uuid: op.record_uuid,
                        reason: 'push_rejected',
                        client_payload: op,
                        server_state: null,
                        error: body.message || `HTTP ${resp.status}`,
                    });
                    await outboxRemove(op.id);
                }
                this._emitOutbox();
                this.dispatchEvent(new CustomEvent('conflict:added', { detail: { count: chunk.length } }));
                continue;
            }

            const data = await resp.json();
            await this._processPushResults(chunk, data);
        }
    }

    async _processPushResults(chunk, data) {
        const results = data.results || [];
        const byOpUuid = new Map(results.map((r) => [r.op_uuid, r]));

        for (const op of chunk) {
            const r = byOpUuid.get(op.op_uuid);
            if (!r) {
                // Server didn't return a result for this op. Treat as
                // unresolved — leave it in the outbox; the dedupe layer
                // will catch it next time.
                continue;
            }

            if (r.status === 'applied' || r.status === 'replayed' || r.status === 'noop') {
                // Persist the canonical server record (if returned)
                // back to IndexedDB so we have the authoritative
                // record_version, server-assigned id, etc.
                if (r.record && op.model) {
                    const finalRecord = { ...r.record, _pending: false };
                    await tx([op.model], 'readwrite', (s) => req(s[op.model].put(finalRecord)));
                }
                await outboxRemove(op.id);
            } else if (r.status === 'conflicted') {
                // Discard the optimistic local change; replace with
                // the server's authoritative state.
                if (r.record && op.model) {
                    await tx([op.model], 'readwrite', (s) => req(s[op.model].put({ ...r.record, _pending: false })));
                }
                await conflictAdd({
                    model: op.model,
                    record_uuid: op.record_uuid,
                    conflict_uuid: r.conflict?.uuid,
                    server_conflict_id: r.conflict?.id,
                    reason: r.reason || 'unknown',
                    client_payload: op,
                    server_state: r.record || r.conflict?.server_state,
                    server_version: r.conflict?.server_version,
                });
                await outboxRemove(op.id);
                this.dispatchEvent(new CustomEvent('conflict:added'));
            } else if (r.status === 'rejected') {
                await conflictAdd({
                    model: op.model,
                    record_uuid: op.record_uuid,
                    reason: r.reason || 'rejected',
                    client_payload: op,
                    error: r.error || 'Server rejected the operation.',
                });
                await outboxRemove(op.id);
                this.dispatchEvent(new CustomEvent('conflict:added'));
            } else if (r.status === 'tombstoned') {
                // The record was deleted on the server; drop locally.
                if (op.model && op.record_uuid) {
                    await tx([op.model], 'readwrite', (s) => req(s[op.model].delete(op.record_uuid)));
                }
                await outboxRemove(op.id);
            } else {
                // Unknown status — keep the op around and surface a
                // diagnostic. Better stuck than silently lost.
                const updated = { ...op, attempts: (op.attempts || 0) + 1, last_error: `unknown status ${r.status}` };
                await outboxUpdate(updated);
            }
        }

        this._emitOutbox();
    }

    _stripInternal(op) {
        const { id, attempts, last_error, created_at, ...wire } = op;
        return wire;
    }

    // -----------------------------------------------------------------
    // Connectivity / events
    // -----------------------------------------------------------------

    async _api(path, opts) {
        const headers = Object.assign(this._authHeaders(), opts.headers || {});
        if (opts.body && !headers['Content-Type']) headers['Content-Type'] = 'application/json';
        if (!headers['Accept']) headers['Accept'] = 'application/json';
        return fetch(path, { ...opts, headers, credentials: 'omit' });
    }

    _authHeaders() {
        return {
            'Authorization': `Bearer ${this._token}`,
            'X-Device-Id': this._deviceId,
        };
    }

    _installListeners() {
        clearInterval(this._timer);
        this._timer = setInterval(() => this.sync().catch(() => {}), SYNC_INTERVAL_MS);

        window.addEventListener('online', () => {
            this._setConnectivity('online');
            this.sync().catch(() => {});
        });
        window.addEventListener('offline', () => this._setConnectivity('offline'));

        // Re-sync on visibilitychange so a returning tab catches up
        // promptly.
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                this.sync().catch(() => {});
            }
        });

        // Listen to background-sync messages from the SW (when the
        // tab was closed across a connectivity change).
        if (navigator.serviceWorker) {
            navigator.serviceWorker.addEventListener('message', (e) => {
                if (e.data && e.data.type === 'sync-request') {
                    this.sync().catch(() => {});
                }
            });
        }
    }

    _setState(state) {
        if (this._state === state) return;
        this._state = state;
        this.dispatchEvent(new CustomEvent('state:change', { detail: { state } }));
    }

    _setConnectivity(state, rttMs = null) {
        // Classify "weak" if RTT high.
        let cls = state;
        if (state === 'online' && rttMs != null && rttMs > WEAK_RTT_MS) cls = 'weak';
        if (this._connectivity === cls) return;
        this._connectivity = cls;
        this.dispatchEvent(new CustomEvent('connectivity', { detail: { state: cls, rtt_ms: rttMs } }));
    }

    async _emitOutbox() {
        const n = await outboxCount();
        this.dispatchEvent(new CustomEvent('outbox:changed', { detail: { count: n } }));
    }
}
