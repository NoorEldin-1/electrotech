<?php

declare(strict_types=1);

use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Models\Project;
use App\Models\WorkOrder;
use App\Sync\Resolvers\AppendOnlyResolver;
use App\Sync\Resolvers\LastWriterWinsResolver;
use App\Sync\Resolvers\ReadOnlyResolver;
use App\Sync\Resolvers\WorkOrderStateMachineResolver;

/**
 * Offline-first sync configuration.
 *
 *   models[]
 *       Wire identifier → resolution table. The wire identifier is a
 *       short string the client uses in pull/push payloads instead of
 *       the fully-qualified PHP class name. Keeping these short and
 *       stable means refactoring the namespace doesn't break clients
 *       already deployed in the field.
 *
 *   resolver
 *       The conflict resolution strategy class. Three built-in choices:
 *
 *         ReadOnlyResolver
 *           Server is the only writer. Push attempts are rejected with
 *           reason=illegal_transition and logged as conflicts so the
 *           admin can spot a misbehaving client.
 *
 *         AppendOnlyResolver
 *           Every push creates a new row (after dedupe). The only failure
 *           cases are validation errors and FK misses — there is no
 *           "version" to be stale on. Ideal for ledger-style data such
 *           as InventoryTransaction.
 *
 *         LastWriterWinsResolver
 *           Scalar fields are arbitrated by record_version. If the client
 *           submits a write whose base_version equals the server's current
 *           record_version, the write applies. Otherwise the server's
 *           state wins; the rejected payload is logged for admin review.
 *
 *         WorkOrderStateMachineResolver
 *           Combines LWW with forward-only state transitions on the WO
 *           lifecycle. "Already advanced" reads as success — see the
 *           idempotency notes in NETWORK_RESILIENCE.md.
 *
 *   eager
 *       Relationships to eager-load when serializing for pull, so the
 *       client gets a coherent denormalized view in one round trip.
 *
 *   pull_scope
 *       Optional closure name (resolved via the container) that narrows
 *       the pull query per user. Used to scope WorkOrder pulls to "WOs
 *       assigned to me OR created by me OR in projects I have access to"
 *       so devices don't drag the entire catalog down.
 *
 *   includes_soft_deleted
 *       Whether the pull query should include soft-deleted rows. Mostly
 *       false; tombstones already communicate deletes. Setting this true
 *       on a model with rare lifecycle changes (e.g. archived projects)
 *       can let clients render history.
 */
return [

    'models' => [
        'project' => [
            'class'                  => Project::class,
            'resolver'               => ReadOnlyResolver::class,
            'eager'                  => [],
            'pull_scope'             => 'project.scope',
            'includes_soft_deleted'  => false,
        ],

        'item' => [
            'class'                  => Item::class,
            'resolver'               => ReadOnlyResolver::class,
            'eager'                  => ['inventory'],
            'pull_scope'             => null,
            'includes_soft_deleted'  => false,
        ],

        'inventory' => [
            'class'                  => Inventory::class,
            'resolver'               => ReadOnlyResolver::class,
            'eager'                  => [],
            'pull_scope'             => null,
            'includes_soft_deleted'  => false,
        ],

        'bom' => [
            'class'                  => Bom::class,
            'resolver'               => ReadOnlyResolver::class,
            'eager'                  => ['items'],
            'pull_scope'             => 'bom.scope',
            'includes_soft_deleted'  => false,
        ],

        'bom_item' => [
            'class'                  => BomItem::class,
            'resolver'               => ReadOnlyResolver::class,
            'eager'                  => [],
            'pull_scope'             => 'bom_item.scope',
            'includes_soft_deleted'  => false,
        ],

        'work_order' => [
            'class'                  => WorkOrder::class,
            'resolver'               => WorkOrderStateMachineResolver::class,
            'eager'                  => ['bom.items'],
            'pull_scope'             => 'work_order.scope',
            'includes_soft_deleted'  => false,
        ],

        'inventory_transaction' => [
            'class'                  => InventoryTransaction::class,
            'resolver'               => AppendOnlyResolver::class,
            'eager'                  => [],
            'pull_scope'             => 'inventory_transaction.scope',
            'includes_soft_deleted'  => false,
        ],
    ],

    /*
     * Push pipeline.
     *
     *   max_operations_per_batch
     *     Server-enforced cap. 200 is enough for a typical full shift of
     *     work for one operator (a couple of WO state transitions plus
     *     ~20 inventory transactions). Beyond that we 413 the request
     *     and tell the client to split — keeps individual transactions
     *     short enough to fit in PHP's request_terminate_timeout.
     *
     *   max_payload_bytes
     *     Defence against a runaway client. 2 MiB.
     */
    'push' => [
        'max_operations_per_batch' => 200,
        'max_payload_bytes'        => 2 * 1024 * 1024,
    ],

    /*
     * Pull pipeline.
     *
     *   page_size
     *     Records per pull response, per model. Smaller batches mean more
     *     round trips on initial sync but each individual transfer is
     *     more likely to complete on a 200 kbps link before the user
     *     gives up and reloads. 100 strikes the balance.
     *
     *   max_lag_seconds
     *     If a device's cursor falls more than this far behind, the API
     *     returns a `force_snapshot: true` hint pushing the client to
     *     re-bootstrap via /snapshot rather than chasing many tiny pages.
     *     Default: 7 days.
     */
    'pull' => [
        'page_size'       => 100,
        'max_lag_seconds' => 7 * 86400,
    ],

    /*
     * Token lifecycle.
     *
     *   inactivity_revoke_days
     *     A token unused for this many days is considered abandoned and
     *     auto-revoked by the SyncJanitor command. 90 days suits factory
     *     personnel with seasonal absences.
     */
    'tokens' => [
        'inactivity_revoke_days' => 90,
    ],
];
