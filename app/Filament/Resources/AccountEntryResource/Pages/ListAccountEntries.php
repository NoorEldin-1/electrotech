<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccountEntryResource\Pages;

use App\Filament\Resources\AccountEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListAccountEntries extends ListRecords
{
    protected static string $resource = AccountEntryResource::class;
}
