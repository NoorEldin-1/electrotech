<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryMinuteResource\Pages;

use App\Filament\Resources\DeliveryMinuteResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDeliveryMinute extends CreateRecord
{
    protected static string $resource = DeliveryMinuteResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
