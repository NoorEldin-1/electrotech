<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerInteractionResource\Pages;

use App\Filament\Resources\CustomerInteractionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCustomerInteractions extends ListRecords
{
    protected static string $resource = CustomerInteractionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
