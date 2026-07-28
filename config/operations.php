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
    | Manufacturing finished notification recipients (التصنيع سلايد 2)
    |--------------------------------------------------------------------------
    |
    | When manufacturing of a work order is marked finished as a whole (the
    | "انتهاء التصنيع" button, regardless of QA stages), users holding these
    | roles are notified that the product is ready for delivery ("تنبيه بقية
    | الأقسام بأن المنتج جاهز للتسليم"). Defaults to every department. Unknown
    | role names are ignored; an empty array disables the alert.
    |
    */
    'manufacturing_finish_notify_roles' => [
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
    | Quality-sheet approval notification recipients (التصنيع سلايد 3)
    |--------------------------------------------------------------------------
    |
    | When the factory manager gives final approval to a quality sheet (QA
    | passed + manager signed), users holding these roles are notified that the
    | operation has finished manufacturing ("تنبيه لجميع الأقسام أن العملية تم
    | الانتهاء من تصنيعها"). Distinct from the "ready for delivery" alert: this
    | is the formal QA sign-off. Defaults to every department. Unknown role
    | names are ignored; an empty array disables the alert.
    |
    */
    'quality_approval_notify_roles' => [
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
    | Offer / Submittal notification recipients (شريحة 11)
    |--------------------------------------------------------------------------
    |
    | An automatic alert when a financial offer is attached to a pipeline
    | (Tender / In-Hand) operation, and likewise when a submittal is uploaded.
    | These roles receive the database (topbar bell) notification. Unknown role
    | names are ignored; an empty array disables that alert.
    |
    */
    'offer_notify_roles' => [
        'Admin',
        'Sales',
        'Sales_Manager',
    ],

    'submittal_notify_roles' => [
        'Admin',
        'Sales',
        'Sales_Manager',
        'Technical_Office',
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

    /*
    |--------------------------------------------------------------------------
    | Manufacturing loss accounts (التصنيع سلايدات 5–6: حساب الهالك)
    |--------------------------------------------------------------------------
    |
    | When a depreciation voucher (إذن إهلاك) is posted, the written-off value
    | is carried from inventory to a loss account by a balanced journal entry
    | (Dr loss / Cr inventory). Abnormal loss (الهالك الغير طبيعي) is booked to
    | the manufacturing-loss account; natural loss (الهالك الطبيعي) stays loaded
    | on the operation and is booked to operating expenses. The accounts are
    | resolved by code; if any is missing the journal is skipped (the stock move
    | still happens), so the feature degrades gracefully without a seeded chart.
    |
    */
    'inventory_account_code' => '1300',
    'abnormal_loss_account_code' => '5060',
    'natural_loss_account_code' => '5010',

    /*
    |--------------------------------------------------------------------------
    | Cost centre closing (Financial Department سلايد 12)
    |--------------------------------------------------------------------------
    |
    | "وعند تسليم العميل بإذن تسليم يتم اقفال مركز التكلفة فى حساب تكلفة
    | البضاعة المباعة". When a delivery voucher is activated and the operation
    | has no work orders still open, the cost still sitting in inventory for
    | that operation is carried to the cost-of-goods-sold account by a balanced
    | journal entry (Dr COGS / Cr inventory). Set the flag to false to keep the
    | closing a purely manual finance decision. The account is resolved by
    | code; if it is missing from the chart the automatic closing is skipped
    | silently — a delivery must never fail over bookkeeping.
    |
    */
    'cogs_account_code' => '5070',
    'auto_close_cost_center' => env('OPERATIONS_AUTO_CLOSE_COST_CENTER', true),

];
