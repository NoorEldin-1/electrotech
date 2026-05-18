<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionType;
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
     * Add stock to an item's inventory (Stock In).
     * Used when receiving PO items or returning goods.
     *
     * @throws \RuntimeException if quantity is non-positive
     */
    public function addStock(
        Item $item,
        float $quantity,
        ?Model $reference = null,
        ?string $notes = null,
    ): InventoryTransaction {
        $this->validatePositiveQuantity($quantity);

        return $this->executeWithLock($item, function () use ($item, $quantity, $reference, $notes) {
            $inventory = $this->getOrCreateInventoryWithLock($item);

            $inventory->on_hand_quantity = (float) $inventory->on_hand_quantity + $quantity;
            $inventory->save();

            return $this->createTransaction(
                item: $item,
                type: TransactionType::In,
                quantity: $quantity,
                reference: $reference,
                notes: $notes,
            );
        });
    }

    /**
     * Deduct stock from an item's inventory (Stock Out).
     * Used when issuing materials to manufacturing.
     *
     * @throws \RuntimeException if insufficient available stock
     */
    public function deductStock(
        Item $item,
        float $quantity,
        ?Model $reference = null,
        ?string $notes = null,
    ): InventoryTransaction {
        $this->validatePositiveQuantity($quantity);

        return $this->executeWithLock($item, function () use ($item, $quantity, $reference, $notes) {
            $inventory = $this->getOrCreateInventoryWithLock($item);

            $available = (float) $inventory->on_hand_quantity - (float) $inventory->on_hold_quantity;

            if ($available < $quantity) {
                throw new \RuntimeException(
                    "Insufficient stock for '{$item->name}'. Available: {$available}, Requested: {$quantity}"
                );
            }

            $inventory->on_hand_quantity = (float) $inventory->on_hand_quantity - $quantity;
            $inventory->save();

            return $this->createTransaction(
                item: $item,
                type: TransactionType::Out,
                quantity: $quantity,
                reference: $reference,
                notes: $notes,
            );
        });
    }

    /**
     * Place a hold/reserve on stock for a project.
     * Reduces available quantity without changing on_hand.
     * Per PDF page 8: reserved vs used quantities concept.
     *
     * @throws \RuntimeException if insufficient stock to hold
     */
    public function holdStock(
        Item $item,
        float $quantity,
        ?Model $reference = null,
        ?string $notes = null,
    ): InventoryTransaction {
        $this->validatePositiveQuantity($quantity);

        return $this->executeWithLock($item, function () use ($item, $quantity, $reference, $notes) {
            $inventory = $this->getOrCreateInventoryWithLock($item);

            $available = (float) $inventory->on_hand_quantity - (float) $inventory->on_hold_quantity;

            if ($available < $quantity) {
                throw new \RuntimeException(
                    "Insufficient available stock for hold on '{$item->name}'. Available: {$available}, Requested: {$quantity}"
                );
            }

            $inventory->on_hold_quantity = (float) $inventory->on_hold_quantity + $quantity;
            $inventory->save();

            return $this->createTransaction(
                item: $item,
                type: TransactionType::Hold,
                quantity: $quantity,
                reference: $reference,
                notes: $notes,
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
    ): InventoryTransaction {
        $this->validatePositiveQuantity($quantity);

        return $this->executeWithLock($item, function () use ($item, $quantity, $reference, $notes) {
            $inventory = $this->getOrCreateInventoryWithLock($item);

            if ((float) $inventory->on_hold_quantity < $quantity) {
                throw new \RuntimeException(
                    "Cannot release {$quantity} for '{$item->name}'. Currently on hold: {$inventory->on_hold_quantity}"
                );
            }

            $inventory->on_hold_quantity = (float) $inventory->on_hold_quantity - $quantity;
            $inventory->save();

            return $this->createTransaction(
                item: $item,
                type: TransactionType::Release,
                quantity: $quantity,
                reference: $reference,
                notes: $notes,
            );
        });
    }

    /**
     * Execute a closure within a Redis Atomic Lock and Database Transaction.
     * Guarantees strict sequential processing to prevent race conditions.
     *
     * @param Item $item The item being modified
     * @param \Closure $callback The transaction logic
     * @return mixed
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
     * Acquire a row-level lock on the inventory record
     * to prevent concurrent modification.
     */
    private function getOrCreateInventoryWithLock(Item $item): Inventory
    {
        $inventory = Inventory::where('item_id', $item->id)
            ->lockForUpdate()
            ->first();

        if (! $inventory) {
            $inventory = Inventory::create([
                'item_id' => $item->id,
                'warehouse_type' => match ($item->type->value) {
                    'finished_good' => 'finished_goods',
                    'semi_finished' => 'work_in_progress',
                    default => 'raw_materials',
                },
                'on_hand_quantity' => 0,
                'on_hold_quantity' => 0,
            ]);

            // Re-acquire with lock after creation
            $inventory = Inventory::where('item_id', $item->id)
                ->lockForUpdate()
                ->firstOrFail();
        }

        return $inventory;
    }

    private function createTransaction(
        Item $item,
        TransactionType $type,
        float $quantity,
        ?Model $reference,
        ?string $notes,
    ): InventoryTransaction {
        $data = [
            'item_id' => $item->id,
            'type' => $type,
            'quantity' => $quantity,
            'notes' => $notes,
            'performed_by' => Auth::id() ?? 1,
        ];

        if ($reference) {
            $data['reference_type'] = $reference->getMorphClass();
            $data['reference_id'] = $reference->getKey();
        }

        return InventoryTransaction::create($data);
    }

    private function validatePositiveQuantity(float $quantity): void
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be positive. Received: ' . $quantity);
        }
    }
}
