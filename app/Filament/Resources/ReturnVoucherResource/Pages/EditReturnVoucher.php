<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReturnVoucherResource\Pages;

use App\Filament\Resources\ReturnVoucherResource;
use App\Models\ReturnVoucher;
use App\Services\ReturnVoucherService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditReturnVoucher extends EditRecord
{
    protected static string $resource = ReturnVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('post')
                ->label(__('resources.return_vouchers.actions.post'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('resources.return_vouchers.actions.post_confirm'))
                ->visible(fn (ReturnVoucher $record) => auth()->user()?->can('post', $record))
                ->action(function (ReturnVoucher $record) {
                    try {
                        app(ReturnVoucherService::class)->post($record);
                        Notification::make()
                            ->title(__('resources.return_vouchers.notifications.posted'))
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
                ->visible(fn (ReturnVoucher $record) => ! $record->isPosted()),
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
