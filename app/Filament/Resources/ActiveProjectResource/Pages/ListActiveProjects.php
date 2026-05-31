<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActiveProjectResource\Pages;

use App\Filament\Resources\ActiveProjectResource;
use Filament\Resources\Pages\ListRecords;

class ListActiveProjects extends ListRecords
{
    protected static string $resource = ActiveProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
