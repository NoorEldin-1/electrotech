<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Enums\AttachmentCategory;
use App\Filament\Concerns\SyncsEntityAttachments;
use App\Filament\Resources\CustomerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    use SyncsEntityAttachments;

    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->pullAttachments($data);
    }

    protected function afterSave(): void
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
