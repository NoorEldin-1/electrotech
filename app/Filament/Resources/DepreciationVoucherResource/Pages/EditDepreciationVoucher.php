<?php

declare(strict_types=1);

namespace App\Filament\Resources\DepreciationVoucherResource\Pages;

use App\Filament\Resources\DepreciationVoucherResource;
use App\Models\DepreciationVoucher;
use App\Services\DepreciationVoucherService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditDepreciationVoucher extends EditRecord
{
    protected static string $resource = DepreciationVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('post')
                ->label(__('resources.depreciation_vouchers.actions.post'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('resources.depreciation_vouchers.actions.post_confirm'))
                ->visible(fn (DepreciationVoucher $record) => auth()->user()?->can('post', $record))
                ->action(function (DepreciationVoucher $record) {
                    try {
                        app(DepreciationVoucherService::class)->post($record);
                        Notification::make()
                            ->title(__('resources.depreciation_vouchers.notifications.posted'))
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
                ->visible(fn (DepreciationVoucher $record) => ! $record->isPosted()),
        ];
    }
}
