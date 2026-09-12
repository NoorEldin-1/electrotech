<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\MasterData;

use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Supplier
 */
class SupplierResource extends JsonResource
{
    use SerializesDecimals;

    /**
     * Whether to include the account balance — a SUM over account_entries, so
     * it stays off for lists and on for the detail endpoint. See
     * CustomerResource for the same reasoning.
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
            'type' => 'supplier',
            'name' => $this->name,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,
            'tax_number' => $this->tax_number,

            // The 1% profit-tax withholding is deducted from every purchase
            // order for this supplier unless they hold an exemption
            // (إعفاء من ضريبة الأرباح). The flag changes the PO total, so the
            // client shows it on the supplier card.
            'profit_tax_exempt' => (bool) $this->profit_tax_exempt,

            'address' => $this->address,
            'notes' => $this->notes,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Positive means we owe the supplier.
            'balance' => $this->when(
                $this->withBalance,
                fn () => $this->money($this->balance),
            ),

            'purchase_orders_count' => $this->whenCounted('purchaseOrders'),
        ];
    }
}
