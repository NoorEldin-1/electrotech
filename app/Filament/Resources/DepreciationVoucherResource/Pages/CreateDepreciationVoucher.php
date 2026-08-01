<?php

declare(strict_types=1);

namespace App\Filament\Resources\DepreciationVoucherResource\Pages;

use App\Filament\Resources\DepreciationVoucherResource;
use App\Models\DepreciationVoucher;
use Filament\Resources\Pages\CreateRecord;

class CreateDepreciationVoucher extends CreateRecord
{
    protected static string $resource = DepreciationVoucherResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['voucher_number'] = DepreciationVoucher::generateVoucherNumber();
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
