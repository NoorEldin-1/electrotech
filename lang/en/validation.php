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
