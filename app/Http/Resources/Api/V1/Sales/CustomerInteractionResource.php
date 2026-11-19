<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Sales;

use App\Http\Api\EnumPresenter;
use App\Models\CustomerInteraction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * متابعة العملاء — one touchpoint with the customer on an operation. The first
 * `contact` and the first `site_readiness` with `site_ready` drive the
 * operation timeline's "Customer Contact" and "Site Readiness" stages.
 *
 * @mixin CustomerInteraction
 */
class CustomerInteractionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'customer_interaction',

            'project_id' => $this->project_id,
            'customer_id' => $this->customer_id,
            'interaction_type' => EnumPresenter::present($this->type),
            'channel' => EnumPresenter::present($this->channel),
            'interacted_at' => $this->interacted_at?->toIso8601String(),
            'site_ready' => (bool) $this->site_ready,
            'notes' => $this->notes,
            'summary' => $this->summary,
            'next_follow_up_at' => $this->next_follow_up_at?->toIso8601String(),
            'created_by' => $this->created_by,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'project' => $this->whenLoaded('project', fn () => $this->project === null ? null : [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
            ]),
        ];
    }
}
