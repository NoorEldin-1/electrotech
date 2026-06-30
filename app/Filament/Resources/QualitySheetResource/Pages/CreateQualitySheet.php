<?php

declare(strict_types=1);

namespace App\Filament\Resources\QualitySheetResource\Pages;

use App\Filament\Resources\QualitySheetResource;
use App\Models\QualitySheet;
use Filament\Resources\Pages\CreateRecord;

class CreateQualitySheet extends CreateRecord
{
    protected static string $resource = QualitySheetResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['sheet_number'] = QualitySheet::generateSheetNumber();
        $data['created_by'] = auth()->id();

        return $data;
    }
}
