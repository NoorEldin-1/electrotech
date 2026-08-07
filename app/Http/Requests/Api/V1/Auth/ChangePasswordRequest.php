<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Proving knowledge of the current password is what stops a stolen
            // token from becoming permanent account ownership: without it, an
            // attacker holding a token could change the password and lock the
            // real user out.
            'current_password' => ['required', 'string'],

            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)->letters()->numbers(),
            ],
        ];
    }
}
