<?php

declare(strict_types=1);

namespace App\Filament\Resources\FinancialClaimResource\Pages;

use App\Filament\Resources\FinancialClaimResource;
use Filament\Resources\Pages\EditRecord;

class EditFinancialClaim extends EditRecord
{
    protected static string $resource = FinancialClaimResource::class;
}
