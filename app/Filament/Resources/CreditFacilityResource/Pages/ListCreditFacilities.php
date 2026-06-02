<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditFacilityResource\Pages;

use App\Filament\Resources\CreditFacilityResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCreditFacilities extends ListRecords
{
    protected static string $resource = CreditFacilityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
