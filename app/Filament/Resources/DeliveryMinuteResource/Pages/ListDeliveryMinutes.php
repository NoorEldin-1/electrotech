<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryMinuteResource\Pages;

use App\Filament\Resources\DeliveryMinuteResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDeliveryMinutes extends ListRecords
{
    protected static string $resource = DeliveryMinuteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
