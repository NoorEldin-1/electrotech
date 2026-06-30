<?php

declare(strict_types=1);

namespace App\Filament\Resources\DepreciationVoucherResource\Pages;

use App\Filament\Resources\DepreciationVoucherResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDepreciationVouchers extends ListRecords
{
    protected static string $resource = DepreciationVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
