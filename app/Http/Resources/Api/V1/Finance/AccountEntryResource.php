<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Finance;

use App\Http\Api\EnumPresenter;
use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\AccountEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * حركة على حساب طرف — one movement on a customer's or supplier's account.
 *
 * These are **written by documents, never by hand**: posting an addition
 * voucher credits its supplier, activating a delivery voucher debits its
 * customer. There is no create, update or delete endpoint, and
 * `AccountEntryPolicy` refuses all three. An editable party statement could be
 * brought into line with a balance somebody expected, rather than with the
 * documents that produced it.
 *
 * `running_balance` is present only on a party's statement, where the rows are
 * ordered and a running total means something. On a filtered or sorted list it
 * would be a number that changes depending on how you looked at it.
 *
 * @mixin AccountEntry
 */
class AccountEntryResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'account_entry',

            'party_type' => $this->party_type,
            'party_id' => $this->party_id,

            'entry_date' => $this->entry_date?->toDateString(),
            // `amount` carries the SIGN and `direction` is the label beside
            // it. A party's balance is the plain sum of these amounts — that
            // is what Customer::getBalanceAttribute, its supplier twin and the
            // panel's running-balance column all compute. Do not re-sign the
            // figure by reading `direction`: a settlement would then add
            // instead of subtracting.
            'direction' => EnumPresenter::present($this->direction),
            'amount' => $this->money($this->amount),

            // What produced this movement — the document class and its id.
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,

            'operation_name' => $this->operation_name,
            'project_id' => $this->project_id,
            'notes' => $this->notes,
            'created_by' => $this->created_by,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Statement endpoint only — see the class note.
            'running_balance' => $this->when(
                $this->resource->running_balance !== null,
                fn () => $this->money($this->resource->running_balance),
            ),
        ];
    }
}
