<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Sales;

use App\Http\Api\EnumPresenter;
use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An operation (عملية) at any stage of the sales pipeline.
 *
 * The field list follows the panel: everything the Tender / In-Hand / Active
 * screens show is here, and nothing they do not. `created_by` is exposed as a
 * name rather than an id-only reference because a mobile list has no second
 * request to spend resolving it.
 *
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'project',
            'name' => $this->name,
            'code' => $this->code,
            'status' => EnumPresenter::present($this->status),

            // The client name is denormalised on the row (free text captured
            // at intake) and may exist without a linked customer record — a
            // tender is often quoted before the customer file is opened.
            'client_name' => $this->client_name,
            'customer_id' => $this->customer_id,

            'consultant_name' => $this->consultant_name,
            'engineer_name' => $this->engineer_name,
            'project_location' => $this->project_location,
            'arrival_method' => EnumPresenter::present($this->arrival_method),

            // Technical specification captured at intake.
            'electric_current' => $this->electric_current,
            'model' => $this->model,
            'section_type' => $this->section_type,
            'poles_count' => $this->poles_count,
            'quantity' => $this->quantity,

            'description' => $this->description,
            'estimated_budget' => $this->money($this->estimated_budget),
            'actual_cost' => $this->money($this->actual_cost),

            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),

            // The bell reminder Sales sets on an operation it is chasing.
            'alarm_at' => $this->alarm_at?->toIso8601String(),
            'alarm_note' => $this->alarm_note,

            // In-Hand stage signals. `smb_status` is derived from whether a
            // Submittal document is on file, so a client should treat it as
            // read-only and upload the document to change it.
            'smb_status' => $this->smb_status,
            'smb_received_at' => $this->smb_received_at?->toDateString(),
            // Project::hasSmb() runs an EXISTS query, so calling it per row
            // turns a 25-row page into 25 extra queries. The index adds a
            // `has_smb` withExists aggregate; when it is present we read that,
            // and the method is the fallback for the single-record endpoints
            // where one query is the right price.
            'has_smb' => $this->has_smb !== null
                ? (bool) $this->has_smb
                : $this->hasSmb(),

            // Both must be present before the operation may move to Active.
            'acceptance_email_at' => $this->acceptance_email_at?->toDateString(),
            'manager_approved_at' => $this->manager_approved_at?->toIso8601String(),

            // Terminal state detail; null unless the operation was lost.
            'lost_reason' => EnumPresenter::present($this->lost_reason),
            'lost_reason_note' => $this->lost_reason_note,
            'winning_competitor' => $this->winning_competitor,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'customer' => $this->whenLoaded(
                'customer',
                fn () => $this->customer === null ? null : [
                    'id' => $this->customer->id,
                    'name' => $this->customer->name,
                ],
            ),

            'created_by' => $this->whenLoaded(
                'createdBy',
                fn () => $this->createdBy === null ? null : [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                ],
            ),

            // The current price. `latestOffer` is a latestOfMany relation, so
            // including it is one extra query for the whole page rather than
            // one per row.
            'latest_offer' => $this->whenLoaded(
                'latestOffer',
                fn () => $this->latestOffer === null
                    ? null
                    : new OfferResource($this->latestOffer),
            ),

            'offers_count' => $this->whenCounted('offers'),
        ];
    }
}
