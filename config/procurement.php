<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Purchase tax rates (شريحة 3)
    |--------------------------------------------------------------------------
    |
    | When a purchase order is created the system ADDS value-added tax
    | (ضريبة القيمة المضافة) and DEDUCTS the commercial & industrial profits
    | withholding tax (ضريبة الأرباح التجارية والصناعية):
    |
    |   total = subtotal + (subtotal × vat%) − (subtotal × profit_tax%)
    |
    | The 1% withholding is skipped for suppliers flagged `profit_tax_exempt`
    | (a supporting document is attached to prove the exemption). The exemption
    | is snapshotted onto each PO as `apply_profit_tax` at creation time, so a
    | later change to the supplier never re-writes historical orders.
    |
    */
    'vat_percentage' => env('PROCUREMENT_VAT_PERCENTAGE', 14),

    'profit_tax_percentage' => env('PROCUREMENT_PROFIT_TAX_PERCENTAGE', 1),

];
