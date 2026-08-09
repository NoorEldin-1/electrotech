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

    /*
    |--------------------------------------------------------------------------
    | قائمة التدفقات النقدية (ماليات.pptx سلايد 9)
    |--------------------------------------------------------------------------
    |
    | The slide asks for two things that cannot both hold. It writes the
    | opening subtotal as "صافى الربح – الاهلاك والمخصصات + ارباح راسمالية",
    | and it ends with the test "ويتم مطابقته مع الواقع" — the derived closing
    | cash must equal the cash the ledger actually holds.
    |
    | Only adding depreciation and provisions BACK (they reduced profit without
    | moving cash), and leaving capital gains unadjusted (their proceeds are
    | already in the cash figure), makes the statement reconcile. The written
    | formula throws it out by 2×(depreciation + provisions) + capital gains.
    |
    | The default (true) therefore follows the slide's own final check. Set it
    | to false — or FINANCE_CASH_FLOW_ADD_BACK=false — to see the statement
    | exactly as the slide words it; the screen labels which one is running.
    |
    */
    'cash_flow' => [
        'add_back_non_cash' => env('FINANCE_CASH_FLOW_ADD_BACK', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | حسابات مراقبة الأطراف (ماليات.pptx سلايد 7)
    |--------------------------------------------------------------------------
    |
    | Control accounts whose pooled balance the balance sheet splits by party
    | balance sign: debit customers are an asset, credit customers (دفعات
    | مقدمة) a current liability, and the same for suppliers. The seeder marks
    | these codes with `accounts.party_control`; an account edited by hand in
    | the admin keeps whatever the admin set.
    |
    */
    'party_control_accounts' => [
        'customer' => ['1200'],           // العملاء
        'supplier' => ['2010', '2011'],   // مورد محلي / مورد خارجي
    ],

];
