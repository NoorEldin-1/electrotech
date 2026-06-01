<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryVoucherResource\Pages;

use App\Filament\Resources\DeliveryVoucherResource;
use App\Models\DeliveryVoucher;
use Filament\Resources\Pages\CreateRecord;

class CreateDeliveryVoucher extends CreateRecord
{
    protected static string $resource = DeliveryVoucherResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['voucher_number'] = DeliveryVoucher::generateVoucherNumber();
        $data['created_by'] = auth()->id();

        return $data;
    }
}
