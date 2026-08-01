<?php

declare(strict_types=1);

namespace App\Filament\Resources\IssueVoucherResource\Pages;

use App\Filament\Resources\IssueVoucherResource;
use App\Models\IssueVoucher;
use Filament\Resources\Pages\CreateRecord;

class CreateIssueVoucher extends CreateRecord
{
    protected static string $resource = IssueVoucherResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['voucher_number'] = IssueVoucher::generateVoucherNumber();
        $data['issued_by'] = auth()->id();

        return $data;
    }

    /**
     * Back to the list after saving — the platform-wide rule (E2E report
     * §5.3): Filament's default lands a create on the record's Edit page,
     * which made "what happens after save" differ from module to module.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
