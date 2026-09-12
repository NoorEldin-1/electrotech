<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Finance;

use App\Http\Api\EnumPresenter;
use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * حساب — one account in the chart of accounts.
 *
 * Three fields decide how the account behaves everywhere else, and none of
 * them is cosmetic:
 *
 *  - **`type`** (asset / liability / equity / revenue / expense) fixes the
 *    account's natural side. `natural_sign` is published because it is what
 *    turns a debit-minus-credit movement into a balance the right way up: an
 *    asset grows on the debit side, a liability on the credit side, and a
 *    client that gets this backwards shows every liability as a negative
 *    number.
 *
 *  - **`nature`** is the account's own declared direction, which may differ
 *    from its type's default for a contra account.
 *
 *  - **`statement_section`** is the axis the financial statements are built
 *    on. An account with no section still posts and still appears in the trial
 *    balance, but it lands nowhere on the income statement or balance sheet —
 *    which is why it is worth setting even though nothing enforces it.
 *
 * `balance` is only present on the single-account endpoint. Computing it means
 * walking the account's posted lines, so a list of two hundred accounts does
 * not carry it.
 *
 * @mixin Account
 */
class AccountResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'account',
            'code' => $this->code,
            'name' => $this->name,
            'name_en' => $this->name_en,

            'account_type' => EnumPresenter::present($this->type),
            'nature' => EnumPresenter::present($this->nature),
            'statement_section' => EnumPresenter::present($this->statement_section),

            // +1 or -1. Multiply it by (debit − credit) to get a balance that
            // reads the way the account is meant to read.
            'natural_sign' => $this->naturalSign(),

            'currency' => $this->currency,
            'parent_id' => $this->parent_id,
            'contra_of_account_id' => $this->contra_of_account_id,

            // Marks a control account whose detail lives on a party's own
            // statement (a customer's or a supplier's), not in the ledger.
            'party_control' => $this->party_control,

            'opening_balance' => $this->money($this->opening_balance),
            'opening_balance_date' => $this->opening_balance_date?->toDateString(),
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Detail endpoint only — see the class note.
            'balance' => $this->when(
                $this->additional['with_balance'] ?? false,
                fn () => $this->money($this->additional['balance'] ?? 0),
            ),

            'parent' => $this->whenLoaded('parent', fn () => $this->parent === null ? null : [
                'id' => $this->parent->id,
                'code' => $this->parent->code,
                'name' => $this->parent->name,
            ]),

            'children_count' => $this->whenCounted('children'),
            'lines_count' => $this->whenCounted('journalLines'),
        ];
    }
}
