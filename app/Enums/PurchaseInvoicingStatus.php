<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * حالة فوترة إذن الإضافة (المشتريات — سلايد 11):
 *   - not_invoiced (غير مفوتر): بضاعة دخلت المخزن ولم تصل فاتورتها بعد —
 *     التزام على الشركة غير مسجَّل بمستند، وهو ما تلاحقه الإدارة المالية.
 *   - invoiced (مفوتر): وصلت فاتورة المورّد ورقمها مسجَّل على الإذن.
 *   - closed_uninvoiced (مُقفل بدون فاتورة): أُقِرَّ أن الإذن لن تصله فاتورة
 *     أبداً، ويُقفل مع كتابة سبب الإقفال.
 *
 * الفصل بين «لم تصل بعد» و«لن تصل أبداً» مقصود: السلايد يذكر حالتين لأن
 * الإقفال عنده نتيجة الحالة الثانية، لكنهما نقيضان محاسبياً — الأولى التزام
 * منتظر، والثانية ملف مغلق.
 */
enum PurchaseInvoicingStatus: string implements HasLabel, HasColor
{
    case NotInvoiced = 'not_invoiced';
    case Invoiced = 'invoiced';
    case ClosedUninvoiced = 'closed_uninvoiced';

    public function getLabel(): string
    {
        return __('resources.enums.purchase_invoicing_status.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::NotInvoiced => 'danger',
            self::Invoiced => 'success',
            self::ClosedUninvoiced => 'gray',
        };
    }
}
