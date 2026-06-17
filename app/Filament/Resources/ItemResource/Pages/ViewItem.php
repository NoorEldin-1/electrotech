<?php

declare(strict_types=1);

namespace App\Filament\Resources\ItemResource\Pages;

use App\Filament\Resources\ItemResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * The item "card" (slide 10) — a read-only screen reachable from a purchase
 * order line so the buyer can inspect an item (unit cost, type, min stock).
 */
class ViewItem extends ViewRecord
{
    protected static string $resource = ItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn () => auth()->user()?->can('items.edit') ?? false),
        ];
    }
}
