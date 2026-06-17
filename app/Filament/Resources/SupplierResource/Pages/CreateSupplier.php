<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierResource\Pages;

use App\Enums\AttachmentCategory;
use App\Filament\Concerns\SyncsEntityAttachments;
use App\Filament\Resources\SupplierResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplier extends CreateRecord
{
    use SyncsEntityAttachments;

    protected static string $resource = SupplierResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->pullAttachments($data);
    }

    protected function afterCreate(): void
    {
        $this->pushAttachments($this->record);
    }

    protected function attachmentCategories(): array
    {
        return AttachmentCategory::supplierCategories();
    }

    protected function attachmentDirPrefix(): string
    {
        return 'supplier-attachments';
    }
}
