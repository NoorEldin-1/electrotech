<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryVoucherResource\Pages;

use App\Filament\Resources\DeliveryVoucherResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDeliveryVouchers extends ListRecords
{
    protected static string $resource = DeliveryVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
