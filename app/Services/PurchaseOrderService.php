<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {}

    /**
     * Receive items from a PO delivery (full or partial).
     * Updates received_quantity on each PO item,
     * adds stock via InventoryService, and updates PO status.
     *
     * @param  array<int, float>  $receivedQuantities  [purchase_order_item_id => quantity_received]
     *
     * @throws \RuntimeException if trying to receive more than ordered
     */
    public function receiveItems(PurchaseOrder $purchaseOrder, array $receivedQuantities): void
    {
        if ($purchaseOrder->status === PurchaseOrderStatus::Cancelled) {
            throw new \RuntimeException(__('errors.purchase_order.cancelled'));
        }

        DB::transaction(function () use ($purchaseOrder, $receivedQuantities) {
            foreach ($receivedQuantities as $poItemId => $quantity) {
                if ($quantity <= 0) {
                    continue;
                }

                $poItem = PurchaseOrderItem::where('purchase_order_id', $purchaseOrder->id)
                    ->where('id', $poItemId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $newReceivedTotal = (float) $poItem->received_quantity + $quantity;

                if ($newReceivedTotal > (float) $poItem->quantity) {
                    throw new \RuntimeException(__('errors.purchase_order.exceeds_ordered', [
                        'quantity' => $quantity,
                        'item' => $poItem->item->name,
                        'ordered' => $poItem->quantity,
                        'received' => $poItem->received_quantity,
                    ]));
                }

                $poItem->received_quantity = $newReceivedTotal;
                $poItem->save();

                $item = Item::findOrFail($poItem->item_id);

                $this->inventoryService->addStock(
                    item: $item,
                    quantity: $quantity,
                    reference: $purchaseOrder,
                    notes: "Received via PO #{$purchaseOrder->po_number}",
                );
            }

            $this->updatePurchaseOrderStatus($purchaseOrder);
        });
    }

    /**
     * Determine and update PO status based on received quantities.
     */
    private function updatePurchaseOrderStatus(PurchaseOrder $purchaseOrder): void
    {
        $purchaseOrder->refresh();
        $items = $purchaseOrder->items;

        $allReceived = $items->every(fn (PurchaseOrderItem $item) => $item->isFullyReceived());
        $anyReceived = $items->contains(fn (PurchaseOrderItem $item) => (float) $item->received_quantity > 0);

        if ($allReceived) {
            $purchaseOrder->status = PurchaseOrderStatus::Received;
        } elseif ($anyReceived) {
            $purchaseOrder->status = PurchaseOrderStatus::PartiallyReceived;
        }

        $purchaseOrder->save();
    }
}
