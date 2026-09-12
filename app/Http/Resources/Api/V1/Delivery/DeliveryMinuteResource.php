<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Delivery;

use App\Models\DeliveryMinute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * محضر تسليم — the minute recording that a delivery was handed over, and the
 * document circulated to every department afterwards.
 *
 * `distributed_at` is the only state it has, and it is one-way: once a minute
 * has gone out to the departments it can no longer be edited or deleted,
 * because people are already acting on the copy they received.
 *
 * @mixin DeliveryMinute
 */
class DeliveryMinuteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'delivery_minute',
            'minute_number' => $this->minute_number,
            'minute_date' => $this->minute_date?->toDateString(),

            'project_id' => $this->project_id,
            'delivery_voucher_id' => $this->delivery_voucher_id,
            'customer_id' => $this->customer_id,

            'content' => $this->content,

            'distributed' => $this->isDistributed(),
            'distributed_at' => $this->distributed_at?->toIso8601String(),

            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'project' => $this->whenLoaded('project', fn () => $this->project === null ? null : [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
            ]),

            'delivery_voucher' => $this->whenLoaded('deliveryVoucher', fn () => $this->deliveryVoucher === null ? null : [
                'id' => $this->deliveryVoucher->id,
                'voucher_number' => $this->deliveryVoucher->voucher_number,
            ]),

            'customer' => $this->whenLoaded('customer', fn () => $this->customer === null ? null : [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
            ]),
        ];
    }
}
