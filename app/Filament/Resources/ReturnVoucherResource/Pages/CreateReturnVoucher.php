<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReturnVoucherResource\Pages;

use App\Filament\Resources\ReturnVoucherResource;
use App\Models\ReturnVoucher;
use Filament\Resources\Pages\CreateRecord;

class CreateReturnVoucher extends CreateRecord
{
    protected static string $resource = ReturnVoucherResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['voucher_number'] = ReturnVoucher::generateVoucherNumber();
        $data['issued_by'] = auth()->id();

        return $data;
    }
}
