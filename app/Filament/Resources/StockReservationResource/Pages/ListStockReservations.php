<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockReservationResource\Pages;

use App\Filament\Resources\StockReservationResource;
use Filament\Resources\Pages\ListRecords;

class ListStockReservations extends ListRecords
{
    protected static string $resource = StockReservationResource::class;
}
