<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditFacilityResource\Pages;

use App\Filament\Resources\CreditFacilityResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCreditFacility extends CreateRecord
{
    protected static string $resource = CreditFacilityResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
