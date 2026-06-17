<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Enums\AttachmentCategory;
use App\Filament\Concerns\SyncsEntityAttachments;
use App\Filament\Resources\CustomerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    use SyncsEntityAttachments;

    protected static string $resource = CustomerResource::class;

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
        return AttachmentCategory::customerCategories();
    }

    protected function attachmentDirPrefix(): string
    {
        return 'customer-attachments';
    }
}
