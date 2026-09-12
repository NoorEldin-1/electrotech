<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Editing your own name/email needs no permission — it is the account
        // you are already authenticated as. Editing *another* user goes
        // through UserController and its policy instead.
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                // `?->` because Scribe evaluates rules() with no authenticated
                // user while generating the docs, and a fatal there aborts the
                // route's extraction. At request time the route is behind
                // auth:sanctum, so the user is always present.
                Rule::unique('users', 'email')->ignore($this->user()?->id),
            ],
        ];
    }
}
