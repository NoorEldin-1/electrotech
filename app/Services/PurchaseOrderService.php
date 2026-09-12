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
     * Approve a draft purchase order, moving it to Submitted so goods can be
     * received against it.
     *
     * The rule used to live inside the Filament resource's approve action,
     * which meant it existed only where somebody clicked a button. Moving it
     * here is design rule #1 from API_Development_Plan.md §1.1: the panel and
     * the API must approve by the same rules, or the phone will happily
     * approve an order the web refuses.
     *
     * The two content checks are not ceremony. An order with no supplier has
     * nobody to send it to and nobody to credit when the goods arrive; an
     * order with no lines commits to nothing and would sit in the Submitted
     * list forever, since a receipt can never complete it.
     *
     * @throws \RuntimeException if the order is not a draft, has no supplier,
     *                           or has no line items
     */
    public function approve(PurchaseOrder $purchaseOrder, ?int $approvedBy = null): PurchaseOrder
    {
        if ($purchaseOrder->status !== PurchaseOrderStatus::Draft) {
            throw new \RuntimeException(__('errors.api.purchase_order_not_draft', [
                'status' => $purchaseOrder->status?->value ?? 'unknown',
            ]));
        }

        // A registered supplier OR a free-text name. Not supplier_id alone:
        // PurchaseOrderFactory and a real class of existing orders carry only
        // `supplier_name` (the supplier file came later than purchase orders),
        // so demanding the foreign key would make those orders unapprovable
        // with no way to fix them short of editing the database.
        if ($purchaseOrder->supplier_id === null && blank($purchaseOrder->supplier_name)) {
            throw new \RuntimeException(__('errors.api.purchase_order_no_supplier'));
        }

        if (! $purchaseOrder->items()->exists()) {
            throw new \RuntimeException(__('errors.api.purchase_order_no_items'));
        }

        $purchaseOrder->update([
            'status' => PurchaseOrderStatus::Submitted,
            'approved_by' => $approvedBy ?? Auth::id(),
            'approved_at' => now(),
        ]);

        return $purchaseOrder->refresh();
    }

    /**
     * An order may only be edited while it is a draft.
     *
     * Once approved it has been sent to a supplier, and once anything has been
     * received against it the line quantities are what the goods-receipt note
     * was matched against. Editing either would rewrite the document the
     * supplier is invoicing from.
     *
     * @throws \RuntimeException if the order has left draft
     */
    public function assertEditable(PurchaseOrder $purchaseOrder): void
    {
        if ($purchaseOrder->status !== PurchaseOrderStatus::Draft) {
            throw new \RuntimeException(__('errors.api.purchase_order_not_editable'));
        }
    }

    /**
     * Replace a draft order's line items and re-derive its money breakdown.
     *
     * Recalculating is not optional: subtotal, VAT and the 1% profit-tax
     * withholding are all stored columns derived from the lines, so lines
     * written without a recalculation leave the order showing the previous
     * total next to the new contents.
     *
     * @param  list<array{item_id: int, quantity: float|string, unit_price: float|string}>  $lines
     *
     * @throws \RuntimeException if the order has left draft
     */
    public function replaceItems(PurchaseOrder $purchaseOrder, array $lines): PurchaseOrder
    {
        $this->assertEditable($purchaseOrder);

        return DB::transaction(function () use ($purchaseOrder, $lines): PurchaseOrder {
            $purchaseOrder->items()->delete();

            foreach ($lines as $line) {
                $purchaseOrder->items()->create([
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'received_quantity' => 0,
                ]);
            }

            $purchaseOrder->recalculateTotal();

            return $purchaseOrder->refresh();
        });
    }

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
