<?php

declare(strict_types=1);

namespace App\Filament\Resources\QualitySheetResource\Pages;

use App\Filament\Resources\QualitySheetResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListQualitySheets extends ListRecords
{
    protected static string $resource = QualitySheetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
