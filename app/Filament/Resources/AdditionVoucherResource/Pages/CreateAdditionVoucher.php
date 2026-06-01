<?php

declare(strict_types=1);

namespace App\Filament\Resources\AdditionVoucherResource\Pages;

use App\Filament\Resources\AdditionVoucherResource;
use App\Models\AdditionVoucher;
use Filament\Resources\Pages\CreateRecord;

class CreateAdditionVoucher extends CreateRecord
{
    protected static string $resource = AdditionVoucherResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['voucher_number'] = AdditionVoucher::generateVoucherNumber();
        $data['received_by'] = auth()->id();

        return $data;
    }
}
