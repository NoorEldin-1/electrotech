<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\MasterData;

use App\Http\Api\AttachmentOwner;
use App\Http\Api\EnumPresenter;
use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Attachment
 */
class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'attachment',
            'file_name' => $this->file_name,
            'file_type' => $this->file_type,
            'file_size' => (int) $this->file_size,
            'category' => EnumPresenter::present($this->category),

            'owner' => [
                'type' => AttachmentOwner::keyFor($this->resource),
                'id' => $this->project_id ?? $this->attachable_id,
            ],

            // A URL that goes through the policy-gated download controller,
            // never the raw storage path. Publishing `file_path` would let
            // anyone who learns the disk layout fetch a contract straight off
            // the public disk with no permission check at all.
            'download_url' => route('api.v1.attachments.download', ['attachment' => $this->id]),

            'uploaded_by' => $this->whenLoaded(
                'uploadedBy',
                fn () => $this->uploadedBy === null ? null : [
                    'id' => $this->uploadedBy->id,
                    'name' => $this->uploadedBy->name,
                ],
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
