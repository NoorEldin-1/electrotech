<?php

declare(strict_types=1);

namespace App\Sync\Resolvers;

use App\Models\DeviceToken;
use App\Models\Item;
use App\Models\SyncTombstone;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only ledger resolver. Used for InventoryTransaction and any
 * other model where each row represents an immutable event (not a
 * mutable record). There are no conflicts at the row level — the
 * dedupe key is the operation uuid, handled one layer up in
 * OperationProcessor.
 *
 * What this resolver does:
 *   - Reject the operation if its record_uuid already exists (the
 *     OperationProcessor would normally catch the dedupe via
 *     SyncOperationLog, but if a request races itself or a previous
 *     batch failed to log, this is a second line of defence).
 *   - Validate FK targets exist (e.g. item_id must point to a real Item).
 *   - For InventoryTransaction specifically, route the write through the
 *     existing InventoryService so the on_hand_quantity adjustment goes
 *     through the same Redis lock as online writes. This prevents the
 *     "two operators consumed the last 10 widgets while both offline"
 *     class of bug: the lock arbitrates them in order of arrival at the
 *     server, and the second one gets an `insufficient_stock` rejection.
 *
 * Note: rejecting an inventory transaction because the warehouse stock
 * went to zero while the operator was offline is a legitimate outcome,
 * not a bug. The conflict log + UI surfaces it so a supervisor can
 * decide what to do (e.g. authorise a negative-stock override).
 */
final class AppendOnlyResolver implements Resolver
{
    public function __construct(
        public readonly string $modelClass,
        public readonly string $modelKey,
    ) {}

    public function resolve(array $operation, DeviceToken $token): ResolverResult
    {
        $modelClass = $this->modelClass;
        $action     = $operation['action'];
        $uuid       = $operation['record_uuid'] ?? null;

        if ($action !== 'upsert') {
            return ResolverResult::rejected(
                'illegal_transition',
                "Action '{$action}' is not supported on append-only model '{$this->modelKey}'."
            );
        }

        if ($uuid === null) {
            return ResolverResult::rejected('validation_failed', 'record_uuid is required.');
        }

        // Tombstone short-circuit — if some admin deleted the record on
        // the server while the client was offline (rare for append-only
        // ledgers, but possible via the conflict-resolution UI's
        // "force_applied" flow), the second attempt has nowhere to go.
        $existingTombstone = SyncTombstone::query()
            ->where('model_type', $modelClass)
            ->where('uuid', $uuid)
            ->exists();
        if ($existingTombstone) {
            return ResolverResult::tombstoned($modelClass, $uuid);
        }

        $existing = $modelClass::query()->where('uuid', $uuid)->first();
        if ($existing !== null) {
            // Same uuid arriving twice — the previous attempt evidently
            // succeeded but the client never got the response. Return
            // the existing row as "applied"; the dedupe layer will flag
            // it as a replay.
            return ResolverResult::applied($existing);
        }

        // Specialised path for inventory transactions so the stock
        // adjustment goes through the InventoryService lock.
        if ($modelClass === \App\Models\InventoryTransaction::class) {
            return $this->applyInventoryTransaction($operation, $token);
        }

        // Generic append for any future append-only model.
        $fields = $this->filterWritable($modelClass, $operation['fields'] ?? []);
        if (! isset($fields['performed_by'])) {
            $fields['performed_by'] = $token->user_id;
        }
        $fields['uuid']              = $uuid;
        $fields['sync_origin']       = $token->device_id;
        $fields['client_updated_at'] = $operation['client_updated_at'] ?? null;

        try {
            /** @var Model $created */
            $created = $modelClass::query()->create($fields);

            return ResolverResult::applied($created);
        } catch (\Throwable $e) {
            return ResolverResult::rejected('validation_failed', $e->getMessage());
        }
    }

    private function applyInventoryTransaction(array $operation, DeviceToken $token): ResolverResult
    {
        $fields = $this->filterWritable(\App\Models\InventoryTransaction::class, $operation['fields'] ?? []);

        $itemId = $fields['item_id'] ?? null;
        $type   = $fields['type'] ?? null;
        $qty    = isset($fields['quantity']) ? (float) $fields['quantity'] : null;

        if (! $itemId || ! $type || $qty === null || $qty <= 0) {
            return ResolverResult::rejected(
                'validation_failed',
                'item_id, type, and quantity (> 0) are required for inventory transactions.'
            );
        }

        $item = Item::query()->find($itemId);
        if ($item === null) {
            return ResolverResult::rejected('fk_missing', "Item id {$itemId} does not exist.");
        }

        /** @var \App\Services\InventoryService $inventory */
        $inventory = app(\App\Services\InventoryService::class);

        $reference = null;
        if (! empty($fields['reference_type']) && ! empty($fields['reference_id'])) {
            $refModel = $fields['reference_type'];
            if (class_exists($refModel)) {
                $reference = $refModel::query()->find($fields['reference_id']);
            }
        }

        // Optional warehouse the offline client targeted; defaults inside the
        // service to the item's home warehouse when null.
        $warehouse = isset($fields['warehouse_type'])
            ? \App\Enums\WarehouseType::tryFrom((string) $fields['warehouse_type'])
            : null;

        // Authenticate the user for the duration of this call so
        // InventoryService::createTransaction picks up performed_by from
        // Auth::id().
        \Illuminate\Support\Facades\Auth::setUser($token->user);

        try {
            $transaction = match ($type) {
                \App\Enums\TransactionType::Out->value, 'out' =>
                    $inventory->deductStock($item, $qty, $reference, $fields['notes'] ?? null, $warehouse),
                \App\Enums\TransactionType::In->value, 'in' =>
                    $inventory->addStock($item, $qty, $reference, $fields['notes'] ?? null, $warehouse),
                \App\Enums\TransactionType::Hold->value, 'hold' =>
                    $inventory->holdStock($item, $qty, $reference, $fields['notes'] ?? null, $warehouse),
                \App\Enums\TransactionType::Release->value, 'release' =>
                    $inventory->releaseStock($item, $qty, $reference, $fields['notes'] ?? null, $warehouse),
                default =>
                    throw new \RuntimeException("Unknown transaction type: {$type}"),
            };

            // The service generated a new InventoryTransaction with a
            // server-side uuid. We need the *client's* uuid to stick so
            // the operation log dedupe works. Overwrite and bump version
            // (still 1 in practice — this is the only write to this row).
            $transaction->uuid              = $operation['record_uuid'];
            $transaction->sync_origin       = $token->device_id;
            $transaction->client_updated_at = $operation['client_updated_at'] ?? null;
            $transaction->saveQuietly();

            return ResolverResult::applied($transaction);
        } catch (\RuntimeException $e) {
            // InventoryService throws on insufficient stock — that's a
            // legitimate business conflict, not a system error.
            return ResolverResult::rejected('insufficient_stock', $e->getMessage());
        } catch (\Throwable $e) {
            return ResolverResult::rejected('validation_failed', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function filterWritable(string $modelClass, array $fields): array
    {
        /** @var Model $proto */
        $proto = new $modelClass();
        $allowed = method_exists($proto, 'syncWritableFields')
            ? $proto->syncWritableFields()
            : $proto->getFillable();

        return array_intersect_key($fields, array_flip($allowed));
    }
}
