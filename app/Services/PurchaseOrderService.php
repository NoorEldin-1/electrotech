<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Enums\VoucherStatus;
use App\Models\AdditionVoucher;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    public function __construct(
        private readonly AdditionVoucherService $additionVoucherService,
    ) {}

    /**
     * Receive items against a PO (slides 1, 7). Receiving now flows through an
     * addition voucher (إذن إضافة) — the single goods-receipt document — instead
     * of touching stock directly, so stock is never double-counted. A voucher is
     * created from the received quantities, linked to the PO, and posted; posting
     * adds the stock once, credits the supplier, and closes the PO by comparison.
     *
     * @param  array<int, float>  $receivedQuantities  [purchase_order_item_id => quantity_received]
     *
     * @throws \RuntimeException if the PO is cancelled or a quantity exceeds the remainder
     */
    public function receiveItems(PurchaseOrder $purchaseOrder, array $receivedQuantities, ?string $invoiceNumber = null): AdditionVoucher
    {
        if ($purchaseOrder->status === PurchaseOrderStatus::Cancelled) {
            throw new \RuntimeException(__('errors.purchase_order.cancelled'));
        }

        return DB::transaction(function () use ($purchaseOrder, $receivedQuantities, $invoiceNumber) {
            $lines = [];

            foreach ($receivedQuantities as $poItemId => $quantity) {
                $quantity = (float) $quantity;
                if ($quantity <= 0) {
                    continue;
                }

                $poItem = PurchaseOrderItem::where('purchase_order_id', $purchaseOrder->id)
                    ->where('id', $poItemId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($quantity > (float) $poItem->remaining_quantity) {
                    throw new \RuntimeException(__('errors.purchase_order.exceeds_ordered', [
                        'quantity' => $quantity,
                        'item' => $poItem->item->name,
                        'ordered' => $poItem->quantity,
                        'received' => $poItem->received_quantity,
                    ]));
                }

                $lines[] = [
                    'item_id' => $poItem->item_id,
                    'quantity' => $quantity,
                    'unit_cost' => (float) $poItem->unit_price,
                ];
            }

            if ($lines === []) {
                throw new \RuntimeException(__('resources.purchase_orders.notifications.no_quantities'));
            }

            $voucher = AdditionVoucher::create([
                'voucher_number' => AdditionVoucher::generateVoucherNumber(),
                'supplier_id' => $purchaseOrder->supplier_id,
                'supplier_name' => $purchaseOrder->supplier_name,
                'purchase_order_id' => $purchaseOrder->id,
                'invoice_number' => $invoiceNumber,
                'voucher_date' => now(),
                'status' => VoucherStatus::Draft,
                'received_by' => Auth::id(),
            ]);

            foreach ($lines as $line) {
                $voucher->lines()->create($line);
            }

            // Posting adds stock once, credits the supplier, and (because the
            // voucher carries purchase_order_id) closes the PO by comparison.
            $this->additionVoucherService->post($voucher);

            return $voucher;
        });
    }
}
