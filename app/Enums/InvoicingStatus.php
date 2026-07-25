<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * حالة فوترة إذن التسليم (المبيعات — سلايد 10):
 *   - fully_invoiced (مفوتر بالكامل): كل قيمة الإذن صدرت لها فواتير.
 *   - partially_invoiced (مفوتر جزئياً): جزء فقط مفوتر — مثل مبيعات التجزئة.
 *   - not_invoiced (غير مفوتر): لا فاتورة — مثل العينات أو السحب الشخصي،
 *     ويُسجَّل السبب في non_invoice_reason.
 */
enum InvoicingStatus: string implements HasLabel, HasColor
{
    case NotInvoiced = 'not_invoiced';
    case PartiallyInvoiced = 'partially_invoiced';
    case FullyInvoiced = 'fully_invoiced';

    public function getLabel(): string
    {
        return __('resources.enums.invoicing_status.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::NotInvoiced => 'danger',
            self::PartiallyInvoiced => 'warning',
            self::FullyInvoiced => 'success',
        };
    }
}
