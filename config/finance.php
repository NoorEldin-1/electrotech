<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Treasury account (قيود اليومية — الإدخال السريع)
    |--------------------------------------------------------------------------
    |
    | When a journal entry is written as an أمر صرف (cash out) or إيصال توريد
    | (cash in), the treasury side of the entry is always the same account, so
    | the form fills it in by itself and leaves it editable (a payment may be
    | made from a bank instead). The account is resolved by its code in the
    | chart of accounts; a currency with its own treasury account overrides the
    | default below. If the code is missing from the chart — a company that
    | numbered its accounts differently — nothing is filled in and the form
    | behaves exactly as before.
    |
    */
    'treasury_account_code' => env('FINANCE_TREASURY_ACCOUNT_CODE', '1010'),

    'treasury_account_codes_by_currency' => [
        'USD' => '1011', // خزينة أجنبي
    ],

];
