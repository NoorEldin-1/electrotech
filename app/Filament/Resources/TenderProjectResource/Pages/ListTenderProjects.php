<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenderProjectResource\Pages;

use App\Filament\Resources\TenderProjectResource;
use Filament\Resources\Pages\ListRecords;

class ListTenderProjects extends ListRecords
{
    protected static string $resource = TenderProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
