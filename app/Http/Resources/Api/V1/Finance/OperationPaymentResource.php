<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Finance;

use App\Http\Api\EnumPresenter;
use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\OperationPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * دفعة / مقبوضات على عملية — cash moving in or out against an operation.
 *
 * `journal_entry_id` is the field to watch. When the platform is configured to
 * post payments automatically and the accounts resolve, recording a payment
 * also writes and posts a balanced journal entry, and links it here. Once that
 * link exists the payment **freezes**: the policy refuses edits and deletes,
 * because the money is already in the ledger and the correction for a posted
 * entry is another entry, not a quiet edit of the first.
 *
 * A payment carrying a `financial_claim_id` is allocated to that claim, and the
 * claim collects itself once it is fully paid.
 *
 * @mixin OperationPayment
 */
class OperationPaymentResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'operation_payment',
            'payment_number' => $this->payment_number,

            'project_id' => $this->project_id,
            'customer_id' => $this->customer_id,
            'financial_claim_id' => $this->financial_claim_id,

            'direction' => EnumPresenter::present($this->direction),
            'method' => EnumPresenter::present($this->method),

            'account_id' => $this->account_id,
            'counter_account_id' => $this->counter_account_id,

            'amount' => $this->money($this->amount),
            'currency' => $this->currency,
            'payment_date' => $this->payment_date?->toDateString(),
            'reference' => $this->reference,
            'notes' => $this->notes,

            // Present once the payment has reached the general ledger. While
            // it is null the payment is still editable; afterwards it is not.
            'journal_entry_id' => $this->journal_entry_id,
            'posted_to_ledger' => $this->journal_entry_id !== null,

            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'project' => $this->whenLoaded('project', fn () => $this->project === null ? null : [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
            ]),

            'customer' => $this->whenLoaded('customer', fn () => $this->customer === null ? null : [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
            ]),
        ];
    }
}
