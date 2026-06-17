<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Enums\AttachmentCategory;
use App\Filament\Concerns\SyncsEntityAttachments;
use App\Filament\Resources\PurchaseOrderResource;
use App\Models\Supplier;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPurchaseOrder extends EditRecord
{
    use SyncsEntityAttachments;

    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    /**
     * Keep the supplier snapshot and the 1%-withholding decision in sync with
     * the chosen supplier (slide 3), and pull the virtual attachment fields aside.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $supplier = isset($data['supplier_id']) ? Supplier::find($data['supplier_id']) : null;

        if ($supplier !== null) {
            $data['supplier_name'] = $supplier->name;
            $data['apply_profit_tax'] = ! $supplier->profit_tax_exempt;
        }

        return $this->pullAttachments($data);
    }

    protected function afterSave(): void
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
