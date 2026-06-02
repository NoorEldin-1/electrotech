<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Activation notification recipients (الإدارة العامة)
    |--------------------------------------------------------------------------
    |
    | When an operation is promoted to active, users holding these roles are
    | notified (سلايد 1: "ترحيلها إلى كل الأقسام"). Unknown role names are
    | ignored. Set to an empty array to disable activation notifications.
    |
    */
    'activation_notify_roles' => [
        'General_Manager',
        'Technical_Office',
        'Procurement',
        'Factory_Manager',
        'Warehouse_Manager',
        'Finance',
        'Sales_Manager',
    ],

    /*
    |--------------------------------------------------------------------------
    | Payments → GL bridge (سلايد 1: الدفعات النقدية)
    |--------------------------------------------------------------------------
    |
    | When enabled, recording an operation payment also posts a balanced
    | journal entry (incoming: Dr treasury/bank, Cr customers control). Off by
    | default to avoid double entries when the GL is kept by hand. The customers
    | control account is resolved by code for incoming payments.
    |
    */
    'auto_journal_payments' => env('OPERATIONS_AUTO_JOURNAL_PAYMENTS', false),
    'customers_account_code' => '1200',

    /*
    |--------------------------------------------------------------------------
    | Installation expenses account (سلايد 2: تحميل مصاريف التركيب)
    |--------------------------------------------------------------------------
    |
    | GL account code that installation expenses are booked against (debit,
    | tagged to the operation). The Operation Cost Center surfaces the subtotal
    | of project-tagged debit lines on this account as "installation expenses".
    |
    */
    'installation_account_code' => '5020',

];
