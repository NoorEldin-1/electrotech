<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Categories for project attachments as listed in Slide 2 of
 * Sales_Department.md. Spellings (Drowing, Speces) intentionally
 * mirror the requirements doc — they are the project's source of
 * truth for these labels.
 */
enum AttachmentCategory: string implements HasIcon, HasLabel
{
    // الرسم المبدئي (Presentation.pptx سلايد 2: «بدلاً من upload هيكون الرسم
    // المبدئي»). The stored value stays `upload` so existing rows and API
    // clients sending `upload` keep working — only the meaning/label changed.
    case PreliminaryDrawing = 'upload';
    case VendorList = 'vendor_list';
    case Drowing = 'drowing';
    case Speces = 'speces';
    case Boq = 'boq';
    // Site measurements raised during the engineering survey (سلايد 1: "رفع
    // مقاسات الموقع"); drawings already covered by Drowing.
    case SiteMeasurement = 'site_measurement';
    // Tender submittal file (شريحة 11): a document compiled and uploaded for
    // tender operations. Sales asked where this is attached — it lives here as
    // its own category so it surfaces in the project Attachments section.
    case Submittal = 'submittal';

    // Supplier file documents (شريحة 3): commercial registry, tax card, and the
    // proof that a supplier is exempt from the 1% profits withholding.
    case CommercialRegistry = 'commercial_registry';
    case TaxCard = 'tax_card';
    case ProfitTaxExemption = 'profit_tax_exemption';

    // Scanned/printed purchase order (شريحة 6): the signed PO image which holds
    // the richer tax detail.
    case PurchaseOrderScan = 'po_scan';

    // Scanned إذن إضافة / goods-receipt note (شريحة 7).
    case AdditionVoucherScan = 'addition_voucher_scan';

    // Customer acceptance / SMB document attached when an operation moves to
    // active — the file, not just a note (شريحة 11).
    case CustomerAcceptance = 'customer_acceptance';

    // General customer-file documents: contracts, IDs, correspondence — any
    // files the sales team wants to keep on the customer record.
    case CustomerDocument = 'customer_document';

    // مرفقات ملف العملية الجديدة (Presentation.pptx سلايد 2). Each one is also
    // a timeline signal: the stage is reached when its file is uploaded.
    case CustomerDrawings = 'customer_drawings';
    case PreliminaryApproval = 'preliminary_approval';
    case ExecutiveDrawing = 'executive_drawing';
    case ExecutiveApproval = 'executive_approval';
    case ConsultantNotes = 'consultant_notes';
    case ConsultantApproval = 'consultant_approval';
    // محاضر تسليم الأعمال — uploading one finishes the running installation.
    case HandoverMinutes = 'handover_minutes';

    /**
     * Project-file categories grouped into the sections of the project form's
     * Attachments panel, in display order. Customer documents and PO /
     * addition-voucher scans live on their own records, not here.
     *
     * @return array<string, array<int, self>>
     */
    public static function projectCategoryGroups(): array
    {
        return [
            'sales' => [self::CustomerDrawings, self::Boq, self::Speces, self::VendorList, self::Submittal, self::CustomerAcceptance],
            'drawings' => [self::PreliminaryDrawing, self::PreliminaryApproval, self::SiteMeasurement, self::Drowing, self::ExecutiveDrawing, self::ExecutiveApproval],
            'consultant' => [self::ConsultantNotes, self::ConsultantApproval],
            'handover' => [self::HandoverMinutes],
        ];
    }

    /**
     * Every category shown on the project form.
     *
     * @return array<int, self>
     */
    public static function projectCategories(): array
    {
        return array_merge(...array_values(self::projectCategoryGroups()));
    }

    /**
     * Supplier-file document categories (slide 3).
     *
     * @return array<int, self>
     */
    public static function supplierCategories(): array
    {
        return [self::CommercialRegistry, self::TaxCard, self::ProfitTaxExemption];
    }

    /**
     * Customer-file document categories — a single general bucket for any
     * files attached to a customer record.
     *
     * @return array<int, self>
     */
    public static function customerCategories(): array
    {
        return [self::CustomerDocument];
    }

    /**
     * Purchase-order document categories (slide 6).
     *
     * @return array<int, self>
     */
    public static function purchaseOrderCategories(): array
    {
        return [self::PurchaseOrderScan];
    }

    /**
     * Addition-voucher document categories (slide 7).
     *
     * @return array<int, self>
     */
    public static function additionVoucherCategories(): array
    {
        return [self::AdditionVoucherScan];
    }

    public function getLabel(): string
    {
        return __('resources.enums.attachment_category.'.$this->value);
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::PreliminaryDrawing => 'heroicon-o-pencil',
            self::VendorList => 'heroicon-o-building-storefront',
            self::Drowing => 'heroicon-o-paint-brush',
            self::Speces => 'heroicon-o-clipboard-document-list',
            self::Boq => 'heroicon-o-calculator',
            self::SiteMeasurement => 'heroicon-o-map',
            self::Submittal => 'heroicon-o-document-check',
            self::CommercialRegistry => 'heroicon-o-building-office',
            self::TaxCard => 'heroicon-o-identification',
            self::ProfitTaxExemption => 'heroicon-o-receipt-percent',
            self::PurchaseOrderScan => 'heroicon-o-document-text',
            self::AdditionVoucherScan => 'heroicon-o-arrow-down-on-square',
            self::CustomerAcceptance => 'heroicon-o-hand-thumb-up',
            self::CustomerDocument => 'heroicon-o-paper-clip',
            self::CustomerDrawings => 'heroicon-o-photo',
            self::PreliminaryApproval => 'heroicon-o-check-badge',
            self::ExecutiveDrawing => 'heroicon-o-square-3-stack-3d',
            self::ExecutiveApproval => 'heroicon-o-shield-check',
            self::ConsultantNotes => 'heroicon-o-chat-bubble-left-ellipsis',
            self::ConsultantApproval => 'heroicon-o-hand-thumb-up',
            self::HandoverMinutes => 'heroicon-o-clipboard-document-check',
        };
    }
}
