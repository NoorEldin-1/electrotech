<?php

declare(strict_types=1);

namespace App\Filament\Resources\AdditionVoucherResource\Pages;

use App\Filament\Resources\AdditionVoucherResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAdditionVouchers extends ListRecords
{
    protected static string $resource = AdditionVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
