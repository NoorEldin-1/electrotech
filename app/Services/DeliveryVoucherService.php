<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountDirection;
use App\Enums\DeliveryVoucherStatus;
use App\Enums\WarehouseType;
use App\Models\AccountEntry;
use App\Models\DeliveryVoucher;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeliveryVoucherService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {}

    /**
     * Record the technical management signature. Activates the voucher if the
     * financial signature is already present.
     */
    public function approveTechnical(DeliveryVoucher $voucher, User $approver): void
    {
        $this->guardApprovable($voucher);

        // Signature + activation are ONE unit of work: if activation fails
        // (e.g. insufficient finished-goods stock) the signature must not
        // survive, otherwise the voucher shows an approval that never took
        // effect and the user is told it failed while the tick stays on.
        DB::transaction(function () use ($voucher, $approver) {
            if (! $voucher->isTechnicalApproved()) {
                $voucher->update([
                    'technical_approved_by' => $approver->id,
                    'technical_approved_at' => now(),
                    'status' => DeliveryVoucherStatus::PendingApproval,
                ]);
            }

            $this->activateIfFullyApproved($voucher->refresh());
        });
    }

    /**
     * Record the financial management signature. Activates the voucher if the
     * technical signature is already present.
     */
    public function approveFinancial(DeliveryVoucher $voucher, User $approver): void
    {
        $this->guardApprovable($voucher);

        // Atomic for the same reason as approveTechnical().
        DB::transaction(function () use ($voucher, $approver) {
            if (! $voucher->isFinancialApproved()) {
                $voucher->update([
                    'financial_approved_by' => $approver->id,
                    'financial_approved_at' => now(),
                    'status' => DeliveryVoucherStatus::PendingApproval,
                ]);
            }

            $this->activateIfFullyApproved($voucher->refresh());
        });
    }

    public function cancel(DeliveryVoucher $voucher): void
    {
        if ($voucher->isActive()) {
            throw new \RuntimeException(__('errors.delivery.cannot_cancel_active', ['number' => $voucher->voucher_number]));
        }

        $voucher->update(['status' => DeliveryVoucherStatus::Cancelled]);
    }

    /**
     * Activate the voucher once both signatures are present: deduct the
     * finished goods and debit the customer's account with the delivery value.
     *
     * @throws \RuntimeException if finished-goods stock is insufficient
     */
    private function activateIfFullyApproved(DeliveryVoucher $voucher): void
    {
        if (! $voucher->isFullyApproved() || $voucher->isActive()) {
            return;
        }

        $voucher->loadMissing(['lines.item', 'customer', 'project']);

        if ($voucher->lines->isEmpty()) {
            throw new \RuntimeException(__('errors.delivery.no_lines', ['number' => $voucher->voucher_number]));
        }

        DB::transaction(function () use ($voucher) {
            $total = 0.0;

            foreach ($voucher->lines as $line) {
                $this->inventoryService->deductStock(
                    item: $line->item,
                    quantity: (float) $line->quantity,
                    reference: $voucher,
                    notes: "Delivery voucher {$voucher->voucher_number}",
                    warehouse: WarehouseType::FinishedGoods,
                    unitCost: (float) $line->unit_cost,
                );

                $total += (float) $line->quantity * (float) $line->unit_cost;
            }

            // Debit the customer account in the name of the operation.
            AccountEntry::create([
                'party_type' => $voucher->customer->getMorphClass(),
                'party_id' => $voucher->customer_id,
                'entry_date' => $voucher->voucher_date,
                'direction' => AccountDirection::Debit,
                'amount' => $total,
                'reference_type' => $voucher->getMorphClass(),
                'reference_id' => $voucher->id,
                'operation_name' => $voucher->project?->name,
                'notes' => $voucher->supply_order_number ? "Supply order #{$voucher->supply_order_number}" : null,
                'created_by' => $voucher->financial_approved_by,
            ]);

            $voucher->update([
                'status' => DeliveryVoucherStatus::Active,
                'total_value' => $total,
                'activated_at' => now(),
            ]);
        });
    }

    private function guardApprovable(DeliveryVoucher $voucher): void
    {
        if ($voucher->status === DeliveryVoucherStatus::Cancelled) {
            throw new \RuntimeException(__('errors.delivery.cancelled', ['number' => $voucher->voucher_number]));
        }

        if ($voucher->isActive()) {
            throw new \RuntimeException(__('errors.delivery.already_active', ['number' => $voucher->voucher_number]));
        }
    }
}
