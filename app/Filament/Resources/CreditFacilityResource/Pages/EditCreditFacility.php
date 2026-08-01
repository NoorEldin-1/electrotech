<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditFacilityResource\Pages;

use App\Filament\Resources\CreditFacilityResource;
use Filament\Resources\Pages\EditRecord;

class EditCreditFacility extends EditRecord
{
    protected static string $resource = CreditFacilityResource::class;

    /**
     * Back to the list after saving — the platform-wide rule (E2E report
     * §5.3), matching what the Create page does, so "what happens after
     * save" no longer differs from module to module.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
