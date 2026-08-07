<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],

            // Required, not optional: without it the devices list is a column
            // of indistinguishable rows and "revoke the tablet I lost" becomes
            // guesswork. The client should send something a human recognises,
            // e.g. "Pixel 8 — Warehouse".
            'device_name' => ['required', 'string', 'max:120'],

            // Optional narrowing of the token. Omitted = the user's full
            // rights. Values are validated against config('api.abilities') in
            // ApiTokenService, which throws a DomainException naming the
            // allowed set — better than an opaque "invalid" from `Rule::in`.
            'abilities' => ['sometimes', 'array', 'max:20'],
            'abilities.*' => ['string', 'max:60'],
        ];
    }
}
