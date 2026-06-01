<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductionEntryResource\Pages;

use App\Filament\Resources\ProductionEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListProductionEntries extends ListRecords
{
    protected static string $resource = ProductionEntryResource::class;
}
