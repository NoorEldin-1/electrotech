<?php

declare(strict_types=1);

namespace App\Filament\Resources\InHandProjectResource\Pages;

use App\Filament\Resources\InHandProjectResource;
use Filament\Resources\Pages\ListRecords;

class ListInHandProjects extends ListRecords
{
    protected static string $resource = InHandProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
