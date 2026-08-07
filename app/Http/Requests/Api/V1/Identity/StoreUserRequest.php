<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Identity;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    /**
     * Authorization must happen HERE, not only in the controller.
     *
     * Laravel runs FormRequest::authorize() before validation but the
     * controller body after it. With the check only in the controller, a
     * caller who lacks users.create and posts an empty body would receive a
     * 422 listing every field and rule — an endpoint they may not use telling
     * them exactly how to use it. Failing 403 first closes that.
     *
     * The controller keeps its own authorize() call: it is the visible gate
     * when reading the controller, and it also covers the routes that have no
     * FormRequest.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\User::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()],

            // A user with no role cannot sign in (see AuthController::login
            // and the panel's canAccessPanel), so requiring at least one role
            // at creation prevents silently making an unusable account.
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ];
    }
}
