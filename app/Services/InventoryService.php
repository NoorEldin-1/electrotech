<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionType;
use App\Enums\WarehouseType;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * Add stock to an item's inventory in a given warehouse (Stock In).
     * Used when receiving PO items (addition voucher) or producing finished goods.
     *
     * New optional params ($warehouse, $unitCost) are appended AFTER the
     * original signature so existing positional callers (sync resolvers) keep
     * working; $warehouse defaults to the item's home warehouse.
     *
     * @throws \RuntimeException if quantity is non-positive
     */
    public function addStock(
        Item $item,
        float $quantity,
        ?Model $reference = null,
        ?string $notes = null,
        ?WarehouseType $warehouse = null,
        ?float $unitCost = null,
    ): InventoryTransaction {
        $this->validatePositiveQuantity($quantity);
        $warehouse ??= $item->homeWarehouse();

        return $this->executeWithLock($item, function () use ($item, $quantity, $reference, $notes, $warehouse, $unitCost) {
            $inventory = $this->getOrCreateInventoryWithLock($item, $warehouse);

            $inventory->on_hand_quantity = (float) $inventory->on_hand_quantity + $quantity;
            $inventory->save();

            return $this->createTransaction(
                item: $item,
                type: TransactionType::In,
                quantity: $quantity,
                warehouse: $warehouse,
                reference: $reference,
                notes: $notes,
                unitCost: $unitCost,
            );
        });
    }

    /**
     * Deduct stock from an item's inventory in a given warehouse (Stock Out).
     * Used when issuing materials to manufacturing or delivering finished goods.
     *
     * @throws \RuntimeException if insufficient available stock
     */
    public function deductStock(
        Item $item,
        float $quantity,
        ?Model $reference = null,
        ?string $notes = null,
        ?WarehouseType $warehouse = null,
        ?float $unitCost = null,
    ): InventoryTransaction {
        $this->validatePositiveQuantity($quantity);
        $warehouse ??= $item->homeWarehouse();

        return $this->executeWithLock($item, function () use ($item, $quantity, $reference, $notes, $warehouse, $unitCost) {
            $inventory = $this->getOrCreateInventoryWithLock($item, $warehouse);

            $available = (float) $inventory->on_hand_quantity - (float) $inventory->on_hold_quantity;

            if ($available < $quantity) {
                throw new \RuntimeException(__('errors.inventory.insufficient_stock', [
                    'item' => $item->name,
                    'warehouse' => $warehouse->getLabel(),
                    'available' => $available,
                    'requested' => $quantity,
                ]));
            }

            $inventory->on_hand_quantity = (float) $inventory->on_hand_quantity - $quantity;
            $inventory->save();

            return $this->createTransaction(
                item: $item,
                type: TransactionType::Out,
                quantity: $quantity,
                warehouse: $warehouse,
                reference: $reference,
                notes: $notes,
                unitCost: $unitCost,
            );
        });
    }

    /**
     * Move stock of an item from one warehouse to another (e.g. raw → WIP on
     * an issue voucher, or WIP → finished goods on a production entry).
     *
     * Both legs run under the same per-item lock and DB transaction, so the
     * source deduction and destination addition are atomic. Two ledger rows
     * are written (Out @source, In @destination) sharing the same reference.
     *
     * @return array{0: InventoryTransaction, 1: InventoryTransaction} [out, in]
     *
     * @throws \RuntimeException if insufficient available stock in the source
     */
    public function transferStock(
        Item $item,
        float $quantity,
        WarehouseType $from,
        WarehouseType $to,
        ?Model $reference = null,
        ?string $notes = null,
        ?float $unitCost = null,
    ): array {
        $this->validatePositiveQuantity($quantity);

        if ($from === $to) {
            throw new \InvalidArgumentException(__('errors.inventory.same_warehouse'));
        }

        return $this->executeWithLock($item, function () use ($item, $quantity, $from, $to, $reference, $notes, $unitCost) {
            $source = $this->getOrCreateInventoryWithLock($item, $from);

            $available = (float) $source->on_hand_quantity - (float) $source->on_hold_quantity;

            if ($available < $quantity) {
                throw new \RuntimeException(__('errors.inventory.insufficient_transfer', [
                    'item' => $item->name,
                    'warehouse' => $from->getLabel(),
                    'available' => $available,
                    'requested' => $quantity,
                ]));
            }

            $source->on_hand_quantity = (float) $source->on_hand_quantity - $quantity;
            $source->save();

            $destination = $this->getOrCreateInventoryWithLock($item, $to);
            $destination->on_hand_quantity = (float) $destination->on_hand_quantity + $quantity;
            $destination->save();

            $out = $this->createTransaction(
                item: $item,
                type: TransactionType::Out,
                quantity: $quantity,
                warehouse: $from,
                reference: $reference,
                notes: $notes ?? "Transfer to {$to->value}",
                unitCost: $unitCost,
            );

            $in = $this->createTransaction(
                item: $item,
                type: TransactionType::In,
                quantity: $quantity,
                warehouse: $to,
                reference: $reference,
                notes: $notes ?? "Transfer from {$from->value}",
                unitCost: $unitCost,
            );

            return [$out, $in];
        });
    }

    /**
     * Place a hold/reserve on stock for a project.
     * Reduces available quantity without changing on_hand.
     *
     * @throws \RuntimeException if insufficient stock to hold
     */
    public function holdStock(
        Item $item,
        float $quantity,
        ?Model $reference = null,
        ?string $notes = null,
        ?WarehouseType $warehouse = null,
        ?float $unitCost = null,
    ): InventoryTransaction {
        $this->validatePositiveQuantity($quantity);
        $warehouse ??= $item->homeWarehouse();

        return $this->executeWithLock($item, function () use ($item, $quantity, $reference, $notes, $warehouse, $unitCost) {
            $inventory = $this->getOrCreateInventoryWithLock($item, $warehouse);

            $available = (float) $inventory->on_hand_quantity - (float) $inventory->on_hold_quantity;

            if ($available < $quantity) {
                throw new \RuntimeException(__('errors.inventory.insufficient_hold', [
                    'item' => $item->name,
                    'warehouse' => $warehouse->getLabel(),
                    'available' => $available,
                    'requested' => $quantity,
                ]));
            }

            $inventory->on_hold_quantity = (float) $inventory->on_hold_quantity + $quantity;
            $inventory->save();

            return $this->createTransaction(
                item: $item,
                type: TransactionType::Hold,
                quantity: $quantity,
                warehouse: $warehouse,
                reference: $reference,
                notes: $notes,
                unitCost: $unitCost,
            );
        });
    }

    /**
     * Release a previously held quantity back to available stock.
     *
     * @throws \RuntimeException if release exceeds held amount
     */
    public function releaseStock(
        Item $item,
        float $quantity,
        ?Model $reference = null,
        ?string $notes = null,
        ?WarehouseType $warehouse = null,
        ?float $unitCost = null,
    ): InventoryTransaction {
        $this->validatePositiveQuantity($quantity);
        $warehouse ??= $item->homeWarehouse();

        return $this->executeWithLock($item, function () use ($item, $quantity, $reference, $notes, $warehouse, $unitCost) {
            $inventory = $this->getOrCreateInventoryWithLock($item, $warehouse);

            if ((float) $inventory->on_hold_quantity < $quantity) {
                throw new \RuntimeException(__('errors.inventory.cannot_release', [
                    'item' => $item->name,
                    'warehouse' => $warehouse->getLabel(),
                    'requested' => $quantity,
                    'held' => $inventory->on_hold_quantity,
                ]));
            }

            $inventory->on_hold_quantity = (float) $inventory->on_hold_quantity - $quantity;
            $inventory->save();

            return $this->createTransaction(
                item: $item,
                type: TransactionType::Release,
                quantity: $quantity,
                warehouse: $warehouse,
                reference: $reference,
                notes: $notes,
                unitCost: $unitCost,
            );
        });
    }

    /**
     * Execute a closure within a Redis Atomic Lock and Database Transaction.
     * The lock is keyed per ITEM (not per warehouse) so that cross-warehouse
     * operations like transferStock cannot deadlock or race against single-
     * warehouse movements for the same item.
     *
     * @throws \Illuminate\Contracts\Cache\LockTimeoutException
     */
    private function executeWithLock(Item $item, \Closure $callback): mixed
    {
        $lockKey = "inventory_lock:item_{$item->id}";

        // Block for up to 10 seconds waiting to acquire the lock. Hold the lock for a maximum of 15 seconds.
        return Cache::lock($lockKey, 15)->block(10, function () use ($callback) {
            return DB::transaction($callback);
        });
    }

    /**
     * Acquire a row-level lock on the (item, warehouse) inventory record,
     * creating it on first use. The outer Redis lock already serializes all
     * writes for this item across the cluster.
     */
    private function getOrCreateInventoryWithLock(Item $item, WarehouseType $warehouse): Inventory
    {
        $inventory = Inventory::where('item_id', $item->id)
            ->where('warehouse_type', $warehouse->value)
            ->lockForUpdate()
            ->first();

        if ($inventory !== null) {
            return $inventory;
        }

        Inventory::create([
            'item_id' => $item->id,
            'warehouse_type' => $warehouse->value,
            'on_hand_quantity' => 0,
            'on_hold_quantity' => 0,
        ]);

        return Inventory::where('item_id', $item->id)
            ->where('warehouse_type', $warehouse->value)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function createTransaction(
        Item $item,
        TransactionType $type,
        float $quantity,
        WarehouseType $warehouse,
        ?Model $reference,
        ?string $notes,
        ?float $unitCost = null,
    ): InventoryTransaction {
        $data = [
            'item_id' => $item->id,
            'type' => $type,
            'warehouse_type' => $warehouse,
            'quantity' => $quantity,
            'unit_cost' => $unitCost ?? (float) $item->unit_cost,
            'notes' => $notes,
            'performed_by' => Auth::id() ?? 1,
        ];

        if ($reference) {
            $data['reference_type'] = $reference->getMorphClass();
            $data['reference_id'] = $reference->getKey();
        }

        $transaction = InventoryTransaction::create($data);

        // Stock just moved — invalidate the cached low-stock count so the
        // dashboard reflects reality on the next render. Cheap O(1) op.
        Cache::forget('dashboard:low_stock_count');

        return $transaction;
    }

    private function validatePositiveQuantity(float $quantity): void
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException(__('errors.inventory.positive_quantity'));
        }
    }
}
