<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Identity;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Whether to include the resolved permission list. Off by default because
     * it is ~190 strings — worth sending on `/auth/me` (the client builds its
     * whole menu from it) and wasteful on a 25-row user list.
     */
    private bool $withPermissions = false;

    public function withPermissions(bool $with = true): self
    {
        $this->withPermissions = $with;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'user',
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Roles are always cheap (a user has one or two) and the client
            // needs them to label the account, so they are unconditional.
            // `whenLoaded` keeps this N+1-free: callers eager-load `roles`.
            'roles' => $this->whenLoaded('roles', fn () => $this->roles
                ->map(fn ($role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                ])
                ->values()
                ->all()),

            // The flat permission set the client uses to hide buttons the user
            // cannot press. It is a UX affordance only — the server is still
            // the sole gate, and every endpoint re-checks its policy.
            'permissions' => $this->when(
                $this->withPermissions,
                fn () => $this->getAllPermissions()->pluck('name')->sort()->values()->all(),
            ),
        ];
    }
}
