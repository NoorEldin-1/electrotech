<?php

declare(strict_types=1);

namespace App\Filament\Resources\LostProjectResource\Pages;

use App\Filament\Resources\LostProjectResource;
use Filament\Resources\Pages\ListRecords;

class ListLostProjects extends ListRecords
{
    protected static string $resource = LostProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
