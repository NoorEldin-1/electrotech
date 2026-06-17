<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Enums\AttachmentCategory;
use App\Filament\Concerns\SyncsEntityAttachments;
use App\Filament\Resources\PurchaseOrderResource;
use App\Models\Supplier;
use Filament\Resources\Pages\CreateRecord;

class CreatePurchaseOrder extends CreateRecord
{
    use SyncsEntityAttachments;

    protected static string $resource = PurchaseOrderResource::class;

    /**
     * Snapshot the supplier name and the 1%-withholding decision onto the PO
     * (slide 3) so a later supplier rename / flag change never rewrites
     * historical orders, and pull the virtual attachment fields aside.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $supplier = isset($data['supplier_id']) ? Supplier::find($data['supplier_id']) : null;

        if ($supplier !== null) {
            $data['supplier_name'] = $supplier->name;
            $data['apply_profit_tax'] = ! $supplier->profit_tax_exempt;
        }

        return $this->pullAttachments($data);
    }

    protected function afterCreate(): void
    {
        $this->record->recalculateTotal();
        $this->pushAttachments($this->record);
    }

    protected function attachmentCategories(): array
    {
        return AttachmentCategory::purchaseOrderCategories();
    }

    protected function attachmentDirPrefix(): string
    {
        return 'po-attachments';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
