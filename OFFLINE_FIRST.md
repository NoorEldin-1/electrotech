# Offline-First Architecture

ElectroTech runs on a factory floor where the WAN goes down for hours
at a time. The 2026-05-17 release shipped a six-layer
*network-resilience* stack ([NETWORK_RESILIENCE.md](NETWORK_RESILIENCE.md))
that turns a weak link into invisible retries — but it explicitly
declined to make the application fully usable while disconnected,
because Filament/Livewire is server-rendered.

This document covers the 2026-05-20 release that adds a true
**offline-first** layer for the factory-floor workflows that can't
afford to wait for the WAN.

## Scope (and the honest non-scope)

| Surface | Offline support | Why |
| --- | --- | --- |
| Filament admin panel (`/admin/*`) | **Network-resilience only** | Server-rendered; every interaction is a server round trip. The existing resilience stack covers this. |
| **Operator Console** (`/console/*`) | **Full offline-first** | A standalone PWA built specifically for factory-floor work. |
| Project / BoM / PO authoring | Online-only | Office workflows. Not on the factory floor. |
| User & role management | Online-only | Admin workflow. |
| Reports & dashboards | Online-only | Aggregates change too quickly to cache usefully. |
| **Viewing assigned Work Orders** | Offline-capable via console | The operator's daily-work view. |
| **Starting / QA-ing / Completing WOs** | Offline-capable via console | Forward-only state machine + per-op idempotency. |
| **Recording inventory consumption** | Offline-capable via console | Append-only ledger via InventoryService lock. |
| **Browsing the item catalog** | Offline-capable via console | Read-only, snapshot-pulled. |

We chose this scope rather than "everything offline" because:

1. **Filament can't run offline.** Each Livewire interaction is a
   server-rendered round trip; making the entire panel work offline
   would mean replacing Filament with a SPA framework — a multi-month
   rewrite for diminishing returns. Office-side workflows already
   tolerate weak links well.

2. **Operators need 4 workflows at most.** A real factory floor survey
   shows operators only need: see what's assigned, start work,
   record output + waste, log material consumption. A dedicated
   surface for those is dramatically simpler than a generic
   offline framework — and dramatically more reliable.

3. **Conflict surface stays small.** With only forward-only state
   transitions and append-only ledger writes, the universe of possible
   conflicts is enumerable and testable.

## What gets installed where

### Server side (Laravel)

```
app/Sync/
├── Concerns/Syncable.php             — trait applied to every syncable Eloquent model
├── Observers/SyncableObserver.php    — maintains uuid/record_version/sync_origin
├── Resolvers/
│   ├── Resolver.php                  — interface
│   ├── ResolverResult.php            — outcome value object
│   ├── ReadOnlyResolver.php
│   ├── AppendOnlyResolver.php
│   ├── LastWriterWinsResolver.php
│   └── WorkOrderStateMachineResolver.php
├── OperationProcessor.php            — orchestrates a push batch
├── PullCoordinator.php               — computes pull delta per (device, model, cursor)
└── SyncScopeRegistry.php             — per-user pull-scope closures

app/Http/
├── Controllers/Sync/{Enroll,Pull,Push,Snapshot}Controller.php
└── Middleware/AuthenticateSyncDevice.php

app/Models/
├── DeviceToken.php
├── SyncTombstone.php
├── SyncConflict.php
└── SyncOperationLog.php

app/Filament/Resources/SyncConflictResource.php   — admin view of unresolved conflicts

app/Providers/SyncServiceProvider.php             — boots the scope registry

config/sync.php                                   — model registry, page sizes, limits
routes/sync.php                                   — /sync/enroll, /pull, /push, /snapshot
database/migrations/2026_05_20_100001_add_sync_columns_to_syncable_tables.php
database/migrations/2026_05_20_100002_create_sync_infrastructure_tables.php
tests/Feature/OfflineFirstSyncTest.php            — 17 tests covering the wire protocol
```

### Client side (the Operator Console PWA)

```
public/console/
├── index.html              — single-page shell
├── manifest.webmanifest    — installable PWA metadata
├── styles.css              — tablet-first, no framework
├── sw.js                   — Service Worker (caches shell, never caches /sync/*)
├── app.js                  — entry point, tab routing, status indicators
├── sync-engine.js          — pull → push → pull loop, conflict detection, outbox FIFO
├── db.js                   — IndexedDB schema + helpers (no library, no Dexie)
├── ui.js                   — tiny DOM helpers
└── views/
    ├── work-orders.js      — list + Start / Submit QA / Complete actions
    ├── inventory.js        — search items, Consume / Receive / Hold / Release
    ├── conflicts.js        — surfaces server-arbitrated conflicts
    └── diagnostics.js      — sync state, cursors, storage, force resnapshot, wipe
```

## How a write travels (offline → online)

```
   ┌──────────────────────┐
   │ Operator taps        │
   │ [Start] on a WO      │
   └─────────┬────────────┘
             │ engine.queueTransition('work_orders', uuid, 'in_progress')
             ▼
   ┌──────────────────────┐    ┌─────────────────────┐
   │ optimistic local     │───▶│ IndexedDB           │
   │ apply, _pending=true │    │ (work_orders store) │
   └─────────┬────────────┘    └─────────────────────┘
             │ outbox.add(op)
             ▼
   ┌──────────────────────┐    ┌─────────────────────┐
   │ IndexedDB outbox     │    │ UI re-renders       │
   │ {op_uuid, base_v, …} │    │ instantly           │
   └─────────┬────────────┘    └─────────────────────┘
             │ (eventually — online event, 30s timer, or manual sync)
             ▼
   ┌──────────────────────┐
   │ POST /sync/push      │  Authorization: Bearer etk_…
   │ Idempotency-Key: …   │  X-Device-Id: …
   └─────────┬────────────┘
             ▼
   ┌──────────────────────┐
   │ AuthenticateSync     │  → resolves DeviceToken, sets Auth::user
   │ Device middleware    │
   └─────────┬────────────┘
             ▼
   ┌──────────────────────┐  per op:
   │ OperationProcessor   │   1. check sync_operation_log for op_uuid → replay if hit
   │ ::process(batch)     │   2. resolver.resolve(op) inside its own DB transaction
   └─────────┬────────────┘   3. record outcome → response_snapshot
             ▼                4. if conflicted → write sync_conflicts row
   ┌──────────────────────┐
   │ Resolver per model:  │
   │ • ReadOnly           │  rejected:illegal_transition
   │ • AppendOnly         │  routes inventory through InventoryService lock
   │ • LWW                │  base_version arbitration
   │ • WorkOrderStateMachine│ idempotent advance + forward-only
   └─────────┬────────────┘
             ▼
   ┌──────────────────────┐
   │ Response 200         │  { results: [...], conflicts: [...], counters: {…} }
   └─────────┬────────────┘
             ▼
   ┌──────────────────────┐    ┌─────────────────────┐
   │ Engine processes     │───▶│ outbox.delete(op)   │
   │ results per op       │    │ IndexedDB clears    │
   │  applied → save      │    │ _pending flag       │
   │  conflicted → log    │    └─────────────────────┘
   │  rejected → log      │
   └──────────────────────┘
```

## Conflict resolution

### Strategies per model

| Model | Strategy | Why |
| --- | --- | --- |
| Project | Read-only | Office workflow. Push rejected with `illegal_transition`. |
| Item | Read-only | Catalog data. |
| Inventory | Read-only at the row level | Changes are side effects of InventoryTransaction. |
| BoM, BomItem | Read-only | Office workflow. |
| WorkOrder | State-machine + LWW | Forward-only transitions; "already advanced" reads as success. |
| InventoryTransaction | Append-only | Each row is an event; no row-level conflicts possible. |

### What "conflict" actually means

- `version_stale` — client's `base_version` is behind the server's
  `record_version`. The local optimistic change is discarded; the
  server's state replaces it; the rejected payload is captured in
  `sync_conflicts` and surfaced in the conflicts tab.
- `illegal_transition` — push of a read-only model, or a state-machine
  transition that would skip steps.
- `insufficient_stock` — InventoryService rejected the stock movement
  because available quantity is too low (this *is* a legitimate
  outcome, not a system error).
- `validation_failed` — the payload is malformed or violates a hard
  model constraint.
- `fk_missing` — a referenced record (item, work order) no longer exists.
- `tombstoned` — the record was deleted on the server while the client
  was offline.

### What we deliberately do NOT do

- **No automatic merge of conflicting field-level edits.** Factory
  workflow is single-owner-per-record. If two operators race on the
  same WO, one wins via record_version and the other gets a conflict
  to acknowledge — not a 3-way merge dialog.

- **No "force apply client payload" in the admin UI.** A rejected
  payload is by definition stale. Replaying it would trample whatever
  the server already accepted. If the admin wants to *re-do* the
  action, they do so through the relevant resource page with current data.

- **No CRDTs.** Real CRDT support adds substantial code and tester
  overhead. The forward-only + append-only constraints make it
  unnecessary for this domain.

## Data integrity guarantees

These are the load-bearing invariants. Any change to the sync layer
should be checked against this list.

1. **Every syncable write has a uuid.** Generated server-side on
   creation; backfilled by migration for pre-existing rows. The uuid
   is the cross-system identity that survives offline authoring.

2. **`record_version` is monotonically increasing per row.** Bumped
   only by the SyncableObserver on actual dirty saves. A client cannot
   set it directly.

3. **Pull cursors advance off `synced_at`, never `updated_at`.**
   `updated_at` can be touched by side-effect saves; `synced_at` is
   maintained exclusively by the observer.

4. **Deletes leave tombstones.** Soft delete and hard delete both
   trigger SyncableObserver::deleted → SyncTombstone::recordDeletion.
   Clients pulling tombstones drop the matching local rows.

5. **`(device_token_id, op_uuid)` is unique in `sync_operation_log`.**
   This is the per-op idempotency guarantee. Retrying an op returns
   the cached response without re-executing.

6. **Each operation runs in its own DB transaction.** A mid-batch
   failure does NOT roll back successful prior ops in the same batch.

7. **The InventoryService lock arbitrates concurrent consumption.**
   Even if two operators are offline at the same time, the second
   to reach the server gets `insufficient_stock` if the first's
   consumption drained the available stock.

8. **No raw token in the database.** Only the SHA-256 hash is stored;
   the raw token is shown to the device exactly once at enrolment.

## Operator workflow on the floor

### Day-1 enrolment (online, supervised)

1. Operator opens `/admin` on their tablet and signs in.
2. They open the user menu → "Operator Console". The browser navigates
   to `/console/`.
3. The console detects no device token. It shows the enrolment dialog.
4. Operator (or supervisor) clicks **Enrol**. The console POSTs to
   `/sync/enroll` using the active web session, receives a bearer
   token, persists it in IndexedDB.
5. The console kicks an initial pull (or snapshot, on first sync) and
   populates the WO list.

### Day-N operation (online or offline)

1. Operator opens `/console/` (works offline because the SW caches the shell).
2. The WO list renders immediately from IndexedDB.
3. Operator taps **Start** on a WO. The local row updates immediately
   (optimistic), the operation goes into the outbox.
4. If online, the sync runs within ~100 ms. If offline, the badge
   shows "1 queued" and the indicator turns red ("offline").
5. When connectivity returns, the sync engine drains the outbox.
6. If the server accepted the op, the badge clears.
7. If the server rejected the op (e.g. someone else advanced the WO
   already, or there isn't enough stock), the conflicts tab badges
   the operator. They open it, see the diff, and either retry with
   current data or escalate.

### Recovery scenarios

- **Browser cache cleared:** the Service Worker re-installs on next
  visit. The console re-loads from `/sync/pull` (cursor missing →
  initial pull). No data loss.

- **Token revoked by admin:** next API call returns 401. The engine
  emits `sync:error`; the operator sees the offline pill stuck.
  Recovery: tap **Sign out** in the topbar (or the Diagnostics tab),
  re-enrol from `/admin`.

- **Local DB corrupt / suspected:** Diagnostics → **Force snapshot
  resync**. Wipes local stores and cursors, pulls fresh from the
  server.

- **Long offline period (> 7 days):** server returns
  `force_snapshot: true` in the pull delta. The engine wipes the
  affected store and re-pulls from zero, avoiding chasing thousands
  of small pages.

## Configuration

`config/sync.php` exposes:

```php
'push.max_operations_per_batch' => 200,       // server cap
'push.max_payload_bytes'        => 2 * 1024 * 1024,
'pull.page_size'                => 100,       // records per model per pull
'pull.max_lag_seconds'          => 7 * 86400, // force_snapshot threshold
'tokens.inactivity_revoke_days' => 90,
```

## Testing

```bash
php artisan test --filter=OfflineFirstSyncTest
```

The suite covers (non-exhaustive):

- Authentication: missing, invalid, and revoked tokens are rejected.
- Pull scoping: operators only see their own WOs.
- Pull cursor advance: idempotent pulls return empty.
- Tombstones: deletions propagate.
- Push apply: WO transitions land and bump record_version.
- Push replay: same op_uuid returns the cached response, does not
  re-execute.
- Stale base_version: creates a sync_conflicts row, server state wins.
- Already-advanced WO: returns `noop`, not `conflict`.
- Inventory append: writes go through InventoryService lock.
- Concurrent consumption: second consumer past available stock gets
  `insufficient_stock`.
- Read-only models reject push with `illegal_transition`.
- Enrolment requires an authenticated web session.
- Re-enrolment revokes the prior token.

### Manual scenarios to walk through

1. **Cold offline start.** Visit `/console/` while online to install
   the SW. Close the tab. Disable Wi-Fi. Reopen the URL. The console
   should render from cache; the connectivity pill should say
   "offline".

2. **Offline WO advance.** Disable Wi-Fi. Tap Start on a Pending WO.
   The card updates and is marked "syncing…". The outbox pill shows
   "1 queued". Re-enable Wi-Fi. Within ~30 s (or instantly if you
   tap ↻) the pill clears.

3. **Cross-user conflict.** From an admin browser, advance a WO
   directly via Filament. On the offline tablet, tap Start on the
   same WO. Re-enable connectivity. The tablet's op should resolve
   as `noop` (already advanced) — not a conflict.

4. **Insufficient stock race.** From two tablets, queue consumption
   of more than the available stock of the same item. Re-enable both.
   First one wins; second one shows a conflict with reason
   `insufficient_stock`.

5. **Force re-snapshot.** Diagnostics → Force snapshot resync. The
   local stores empty, then re-populate from the server.

## What's intentionally future work

- **Background push from a closed tab.** Modern browsers' Background
  Sync API is unreliable — Safari doesn't implement it at all. The
  engine relies on the user opening the console for a sync; in
  practice operators do this within minutes of arriving at their
  workstation.

- **Selective pre-cache for new operators.** Currently a fresh enrolment
  pulls the operator's full WO scope. For a new operator on day one
  this is small; for a transferred operator inheriting a big WO history
  it can be megabytes. A future scoping policy could pull only WOs
  modified in the last 90 days.

- **End-to-end encryption of the IndexedDB.** Browser-level OS storage
  is sufficient for the threat model (factory-issued devices with
  device-level encryption). If we ever support BYOD, we'd encrypt at
  the IndexedDB layer with a key derived from the bearer token.

- **Real-time push from server → device.** WebSocket / SSE updates so
  one operator's accepted advance instantly invalidates the cache on
  another's tablet. The 30-second pull interval is adequate for now.

## Glossary

- **Pull cursor:** the `(synced_at, last_id)` tuple a device stores
  per model, used as the lower bound for the next pull.
- **Outbox:** the IndexedDB queue of operations the device has
  authored locally but not yet pushed.
- **Tombstone:** a row in `sync_tombstones` marking a deleted record
  so clients can purge their local copy on next pull.
- **op_uuid:** a client-minted identifier per individual operation,
  used for per-op idempotency in the push pipeline.
- **base_version:** the `record_version` the client believed was
  current when it authored the local change. The server compares
  this to the actual current `record_version` to detect concurrent
  edits.
- **Resolver:** the per-model strategy class that decides what to do
  with an incoming push operation.
