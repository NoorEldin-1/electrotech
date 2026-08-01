<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| English validation overrides
|--------------------------------------------------------------------------
|
| Laravel's FileLoader merges the framework's built-in English validation
| strings with this file (array_replace_recursive), so we only need to add
| what the framework does not ship: the custom `phone` rule message used by
| App\Filament\Support\PhoneInput, plus friendly attribute names.
|
*/

return [

    'phone' => 'The phone number format is invalid.',

    /*
    | Client-side (inline) validation messages — rendered by
    | public/js/inline-validation.js in place of the browser's native bubble.
    */
    'client' => [
        'required' => 'This field is required.',
        'email' => 'Enter a valid email address.',
        'url' => 'Enter a valid URL.',
        'pattern' => 'This value is not in the expected format.',
        'min_length' => 'Enter at least :min characters.',
        'max_length' => 'Enter no more than :max characters.',
        'min' => 'The value must be at least :min.',
        'max' => 'The value must not exceed :max.',
        'step' => 'This value is not an allowed increment.',
        'invalid' => 'This value is not valid.',
    ],

    'attributes' => [
        'name' => 'name',
        'contact_person' => 'contact person',
        'phone' => 'phone',
        'email' => 'email',
        'tax_number' => 'tax number',
        'address' => 'address',
        'notes' => 'notes',
    ],

];
