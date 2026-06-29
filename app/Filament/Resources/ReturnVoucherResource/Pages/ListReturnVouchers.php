<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReturnVoucherResource\Pages;

use App\Filament\Resources\ReturnVoucherResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListReturnVouchers extends ListRecords
{
    protected static string $resource = ReturnVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
