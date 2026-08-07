<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Identity;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class StoreRoleRequest extends FormRequest
{
    /**
     * Runs before validation, so an unauthorized caller gets 403 rather than a
     * 422 that discloses the endpoint's rules. See StoreUserRequest.
     */
    public function authorize(): bool
    {
        $target = $this->route('role');

        return $target === null
            ? ($this->user()?->can('create', Role::class) ?? false)
            : ($this->user()?->can('update', $target) ?? false);
    }

    public function rules(): array
    {
        /** @var \Spatie\Permission\Models\Role|null $target */
        $target = $this->route('role');

        return [
            'name' => [
                $target === null ? 'required' : 'sometimes',
                'string',
                'max:120',
                // Role names end up in permission checks and in the panel's
                // navigation; keep them to a safe identifier shape.
                'regex:/^[A-Za-z0-9 _-]+$/',
                Rule::unique('roles', 'name')
                    ->where('guard_name', 'web')
                    ->ignore($target?->id),
            ],

            // Permission names must exist in the catalog seeded by
            // RoleAndPermissionSeeder. Inventing one here would create a
            // permission nothing ever checks — a silent no-op that looks like
            // a granted right on the roles screen.
            'permissions' => [$target === null ? 'required' : 'sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ];
    }
}
