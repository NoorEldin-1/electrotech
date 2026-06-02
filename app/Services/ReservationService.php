<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BomStatus;
use App\Enums\ReservationStatus;
use App\Enums\WarehouseType;
use App\Models\Item;
use App\Models\Project;
use App\Models\StockReservation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * حجز الكمية للعملية — reserves stock for an operation by wiring the existing
 * InventoryService hold/release primitives to a project (سلايد 1). Holding
 * reduces available quantity without touching on-hand; releasing returns it
 * (e.g. when materials are issued).
 */
class ReservationService
{
    public function __construct(private readonly InventoryService $inventory)
    {
    }

    /**
     * Reserve a quantity of an item for an operation. Throws (via
     * InventoryService) if the available quantity is insufficient.
     */
    public function reserveForProject(
        Project $project,
        Item $item,
        float $quantity,
        ?WarehouseType $warehouse = null,
        ?string $notes = null,
    ): StockReservation {
        $warehouse ??= $item->homeWarehouse();

        return DB::transaction(function () use ($project, $item, $quantity, $warehouse, $notes) {
            $reservation = StockReservation::create([
                'project_id' => $project->id,
                'item_id' => $item->id,
                'warehouse_type' => $warehouse,
                'quantity' => $quantity,
                'status' => ReservationStatus::Active,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);

            $this->inventory->holdStock(
                item: $item,
                quantity: $quantity,
                reference: $reservation,
                warehouse: $warehouse,
            );

            return $reservation;
        });
    }

    /**
     * Release a reservation back to available stock. Idempotent — a released
     * reservation is left untouched.
     */
    public function release(StockReservation $reservation): void
    {
        if (! $reservation->isActive()) {
            return;
        }

        DB::transaction(function () use ($reservation) {
            $this->inventory->releaseStock(
                item: $reservation->item,
                quantity: (float) $reservation->quantity,
                reference: $reservation,
                warehouse: $reservation->warehouse_type,
            );

            $reservation->update([
                'status' => ReservationStatus::Released,
                'released_at' => now(),
            ]);
        });
    }

    /**
     * Reserve the full material requirement of an operation's latest approved
     * BOM (each line's waste-adjusted quantity), from each item's home
     * warehouse. Returns the created reservations.
     *
     * @return Collection<int, StockReservation>
     */
    public function reserveApprovedBomFor(Project $project): Collection
    {
        $bom = $project->boms()
            ->where('status', BomStatus::Approved->value)
            ->latest('version')
            ->with('items.item')
            ->first();

        $reservations = collect();

        if ($bom === null) {
            return $reservations;
        }

        foreach ($bom->items as $line) {
            $reservations->push(
                $this->reserveForProject(
                    project: $project,
                    item: $line->item,
                    quantity: (float) $line->total_required_quantity,
                )
            );
        }

        return $reservations;
    }
}
