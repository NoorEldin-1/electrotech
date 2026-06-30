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
}
