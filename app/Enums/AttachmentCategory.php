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
enum AttachmentCategory: string implements HasLabel, HasIcon
{
    case Upload = 'upload';
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
        return __('resources.enums.attachment_category.' . $this->value);
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Upload => 'heroicon-o-arrow-up-tray',
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
        };
    }
}
