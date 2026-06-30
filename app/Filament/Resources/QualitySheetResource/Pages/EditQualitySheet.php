<?php

declare(strict_types=1);

namespace App\Filament\Resources\QualitySheetResource\Pages;

use App\Filament\Resources\QualitySheetResource;
use App\Models\QualitySheet;
use App\Services\QualitySheetService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditQualitySheet extends EditRecord
{
    protected static string $resource = QualitySheetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('fill')
                ->label(__('resources.quality_sheets.actions.fill'))
                ->icon('heroicon-o-clipboard-document-check')
                ->color('warning')
                ->visible(fn (QualitySheet $record) => auth()->user()?->can('fill', $record))
                ->form([
                    \Filament\Forms\Components\Textarea::make('qa_inspector_notes')
                        ->label(__('resources.quality_sheets.fields.qa_inspector_notes'))
                        ->default(fn (QualitySheet $record) => $record->qa_inspector_notes)
                        ->rows(3),
                ])
                ->action(function (QualitySheet $record, array $data) {
                    try {
                        app(QualitySheetService::class)->fill($record, $data['qa_inspector_notes'] ?? null);
                        Notification::make()->success()->title(__('resources.quality_sheets.notifications.filled'))->send();
                        $this->refreshFormData(['status', 'qa_filled_by', 'qa_filled_at', 'qa_inspector_notes']);
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title(__('resources.common.action_failed'))->body($e->getMessage())->send();
                    }
                }),

            Actions\Action::make('approve')
                ->label(__('resources.quality_sheets.actions.approve'))
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('resources.quality_sheets.actions.approve_confirm'))
                ->visible(fn (QualitySheet $record) => $record->status === \App\Enums\QualitySheetStatus::QaFilled
                    && auth()->user()?->can('approve', $record))
                ->action(function (QualitySheet $record) {
                    try {
                        app(QualitySheetService::class)->approve($record);
                        Notification::make()->success()->title(__('resources.quality_sheets.notifications.approved'))->send();
                        $this->redirect(static::getResource()::getUrl('index'));
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title(__('resources.common.action_failed'))->body($e->getMessage())->send();
                    }
                }),

            Actions\DeleteAction::make()
                ->visible(fn (QualitySheet $record) => ! $record->isApproved()),
        ];
    }
}
