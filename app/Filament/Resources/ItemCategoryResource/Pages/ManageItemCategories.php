<?php

declare(strict_types=1);

namespace App\Filament\Resources\ItemCategoryResource\Pages;

use App\Filament\Resources\ItemCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageItemCategories extends ManageRecords
{
    protected static string $resource = ItemCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
