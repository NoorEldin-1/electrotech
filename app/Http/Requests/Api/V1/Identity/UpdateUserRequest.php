<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Identity;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    /**
     * Runs before validation, so an unauthorized caller gets 403 rather than a
     * 422 that discloses the endpoint's rules. See StoreUserRequest.
     */
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target !== null && ($this->user()?->can('update', $target) ?? false);
    }

    public function rules(): array
    {
        /** @var \App\Models\User|null $target */
        $target = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($target?->id),
            ],

            // Optional on update. An administrator resetting someone's
            // password does not need to know the old one — that is the point
            // of the users.edit permission. All of the target's sessions are
            // revoked in the controller when this is present.
            'password' => ['sometimes', 'required', 'string', Password::min(8)->letters()->numbers()],

            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ];
    }
}
