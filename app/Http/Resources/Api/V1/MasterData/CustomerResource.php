<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\MasterData;

use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    use SerializesDecimals;

    /**
     * Whether to include the account balance. Off on lists: `balance` is a
     * SUM over account_entries, so rendering it for 25 rows is 25 aggregate
     * queries. The detail endpoint turns it on for the one record.
     */
    private bool $withBalance = false;

    public function withBalance(bool $with = true): self
    {
        $this->withBalance = $with;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'customer',
            'name' => $this->name,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,
            'tax_number' => $this->tax_number,
            'address' => $this->address,
            'notes' => $this->notes,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Positive means the customer owes us. Signed, not absolute — a
            // client that renders |balance| would show an overpayment as a
            // debt.
            'balance' => $this->when(
                $this->withBalance,
                fn () => $this->money($this->balance),
            ),

            'projects_count' => $this->whenCounted('projects'),
        ];
    }
}
