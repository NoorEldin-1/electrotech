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
        };
    }
}
