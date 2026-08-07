<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Identity;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One active sign-in session ("device") belonging to the caller.
 *
 * The token itself is NEVER serialized — not even hashed. Sanctum stores only
 * a hash, and this resource exposes metadata so the user can recognise a
 * session and revoke it. The plain-text token exists exactly once, in the
 * login/refresh response.
 *
 * @mixin PersonalAccessToken
 */
class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'device',
            'name' => $this->name,
            'abilities' => $this->abilities ?? ['*'],
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            // Lets the app grey out the "revoke" button on the session the
            // user is currently signed in with, instead of letting them log
            // themselves out by accident from a list of similar device names.
            'is_current' => $this->id === $request->user()?->currentAccessToken()?->getKey(),
        ];
    }
}
