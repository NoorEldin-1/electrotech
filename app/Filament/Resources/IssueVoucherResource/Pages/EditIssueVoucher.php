<?php

declare(strict_types=1);

namespace App\Filament\Resources\IssueVoucherResource\Pages;

use App\Filament\Resources\IssueVoucherResource;
use App\Models\IssueVoucher;
use App\Services\IssueVoucherService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditIssueVoucher extends EditRecord
{
    protected static string $resource = IssueVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('post')
                ->label(__('resources.issue_vouchers.actions.post'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('resources.issue_vouchers.actions.post_confirm'))
                ->visible(fn (IssueVoucher $record) => auth()->user()?->can('post', $record))
                ->action(function (IssueVoucher $record) {
                    try {
                        app(IssueVoucherService::class)->post($record);
                        Notification::make()
                            ->title(__('resources.issue_vouchers.notifications.posted'))
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
                ->visible(fn (IssueVoucher $record) => ! $record->isPosted()),
        ];
    }

    /**
     * Back to the list after saving — the platform-wide rule (E2E report
     * §5.3), matching what the Create page does, so "what happens after
     * save" no longer differs from module to module.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
