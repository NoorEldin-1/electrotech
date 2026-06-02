<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntryResource\Pages;

use App\Filament\Resources\JournalEntryResource;
use App\Models\JournalEntry;
use App\Services\JournalEntryService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditJournalEntry extends EditRecord
{
    protected static string $resource = JournalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('post')
                ->label(__('resources.journal_entries.actions.post'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('resources.journal_entries.actions.post_confirm'))
                ->visible(fn (JournalEntry $record) => auth()->user()?->can('post', $record))
                ->action(function (JournalEntry $record) {
                    try {
                        app(JournalEntryService::class)->post($record);
                        Notification::make()
                            ->title(__('resources.journal_entries.notifications.posted'))
                            ->success()
                            ->send();

                        $this->redirect(static::getResource()::getUrl('index'));
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title(__('resources.common.action_failed'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Actions\DeleteAction::make()
                ->visible(fn (JournalEntry $record) => $record->isDraft()),
        ];
    }

    protected function afterSave(): void
    {
        $this->record->recalculateTotals();
    }
}
