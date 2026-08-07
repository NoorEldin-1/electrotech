<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Identity;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Role;

/**
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'role',
            // Roles are administrator-created strings with no translation key
            // — the panel's RoleResource renders them raw too. Keeping the API
            // identical avoids inventing a label the web UI does not show.
            'name' => $this->name,
            'guard_name' => $this->guard_name,
            'users_count' => $this->whenCounted('users'),
            'permissions' => $this->whenLoaded(
                'permissions',
                fn () => $this->permissions->pluck('name')->sort()->values()->all(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
