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

    /** @var array<int, array<string, mixed>> */
    protected array $debitLines = [];

    /** @var array<int, array<string, mixed>> */
    protected array $creditLines = [];

    /**
     * The form edits مدين and دائن as two separate columns; the record keeps
     * them in one table, so they are split on the way in and written back on
     * the way out.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, ...JournalEntryService::splitLines($this->record)];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->debitLines = $data['debit_lines'] ?? [];
        $this->creditLines = $data['credit_lines'] ?? [];

        unset($data['debit_lines'], $data['credit_lines'], $data['show_line_details']);

        return $data;
    }

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
        app(JournalEntryService::class)->syncLines($this->record, $this->debitLines, $this->creditLines);
    }
}
