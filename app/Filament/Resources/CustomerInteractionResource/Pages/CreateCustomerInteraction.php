<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerInteractionResource\Pages;

use App\Filament\Resources\CustomerInteractionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomerInteraction extends CreateRecord
{
    protected static string $resource = CustomerInteractionResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
