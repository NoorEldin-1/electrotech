<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Delivery;

use App\Models\SiteSurvey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * معاينة الموقع — the site visit that precedes an installation.
 *
 * `measurements` is free text, not a structured field, and that is a decision
 * rather than an omission: what a surveyor needs to write down differs by site
 * and by product, and a schema that fits the last ten sites would silently
 * lose the eleventh. It is read back verbatim.
 *
 * @mixin SiteSurvey
 */
class SiteSurveyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'site_survey',

            'project_id' => $this->project_id,
            'survey_date' => $this->survey_date?->toDateString(),
            'measurements' => $this->measurements,
            'notes' => $this->notes,
            'surveyed_by' => $this->surveyed_by,

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
