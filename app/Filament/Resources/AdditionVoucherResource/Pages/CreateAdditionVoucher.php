<?php

declare(strict_types=1);

namespace App\Filament\Resources\AdditionVoucherResource\Pages;

use App\Enums\AttachmentCategory;
use App\Filament\Concerns\SyncsEntityAttachments;
use App\Filament\Resources\AdditionVoucherResource;
use App\Models\AdditionVoucher;
use Filament\Resources\Pages\CreateRecord;

class CreateAdditionVoucher extends CreateRecord
{
    use SyncsEntityAttachments;

    protected static string $resource = AdditionVoucherResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['voucher_number'] = AdditionVoucher::generateVoucherNumber();
        $data['received_by'] = auth()->id();

        return $this->pullAttachments($data);
    }

    protected function afterCreate(): void
    {
        $this->pushAttachments($this->record);
    }

    protected function attachmentCategories(): array
    {
        return AttachmentCategory::additionVoucherCategories();
    }

    protected function attachmentDirPrefix(): string
    {
        return 'addition-voucher-attachments';
    }
}
