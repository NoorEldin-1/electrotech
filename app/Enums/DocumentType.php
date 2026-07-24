<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The three colour-coded document numbers a journal entry can carry
 * (Financial_Department.md — سلايد 2):
 *   ⚫ أمر صرف     payment_order  — cash going out   (black → gray)
 *   🔴 إيصال توريد supply_receipt — cash coming in   (red   → danger)
 *   🟢 قيد تسوية   settlement     — adjustment        (green → success)
 *
 * Each type keeps its own running document-number sequence (see prefix()).
 */
enum DocumentType: string implements HasLabel, HasColor
{
    case PaymentOrder = 'payment_order';
    case SupplyReceipt = 'supply_receipt';
    case Settlement = 'settlement';

    public function getLabel(): string
    {
        return __('resources.enums.document_type.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::PaymentOrder => 'gray',   // أسود
            self::SupplyReceipt => 'danger', // أحمر
            self::Settlement => 'success',  // أخضر
        };
    }

    /**
     * CSS colour of the document number as it is printed in the daybook:
     * black for a payment order, red for a supply receipt, green for a
     * settlement (قائمة المواد.pptx سلايد 2).
     */
    public function documentNumberHexColor(): string
    {
        return match ($this) {
            self::PaymentOrder => '#111827',
            self::SupplyReceipt => '#dc2626',
            self::Settlement => '#16a34a',
        };
    }

    /**
     * Document-number prefix used when generating the entry number.
     */
    public function prefix(): string
    {
        return match ($this) {
            self::PaymentOrder => 'PV',
            self::SupplyReceipt => 'RV',
            self::Settlement => 'JV',
        };
    }
}
