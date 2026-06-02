<?php

declare(strict_types=1);

namespace App\Filament\Resources\FinancialClaimResource\Pages;

use App\Filament\Resources\FinancialClaimResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFinancialClaims extends ListRecords
{
    protected static string $resource = FinancialClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
