<?php

declare(strict_types=1);

namespace App\Filament\Resources\SyncConflictResource\Pages;

use App\Filament\Resources\SyncConflictResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewSyncConflict extends ViewRecord
{
    protected static string $resource = SyncConflictResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('resolve')
                ->label(__('resources.sync_conflicts.actions.resolve'))
                ->icon('heroicon-o-check')
                ->visible(fn () => $this->record->resolved_at === null)
                ->requiresConfirmation()
                ->modalDescription(__('resources.sync_conflicts.actions.resolve_confirmation'))
                ->action(function (): void {
                    $this->record->update([
                        'resolved_at' => now(),
                        'resolved_by' => auth()->id(),
                        'resolution'  => 'accepted_server',
                    ]);
                }),
        ];
    }
}
