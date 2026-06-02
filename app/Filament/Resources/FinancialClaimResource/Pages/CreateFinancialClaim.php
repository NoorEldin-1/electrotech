<?php

declare(strict_types=1);

namespace App\Filament\Resources\FinancialClaimResource\Pages;

use App\Filament\Resources\FinancialClaimResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFinancialClaim extends CreateRecord
{
    protected static string $resource = FinancialClaimResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
