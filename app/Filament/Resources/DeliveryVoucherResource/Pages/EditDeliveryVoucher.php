<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryVoucherResource\Pages;

use App\Filament\Resources\DeliveryVoucherResource;
use App\Models\DeliveryVoucher;
use App\Services\DeliveryVoucherService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditDeliveryVoucher extends EditRecord
{
    protected static string $resource = DeliveryVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('approve_technical')
                ->label(__('resources.delivery_vouchers.actions.approve_technical'))
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('info')
                ->requiresConfirmation()
                ->visible(fn (DeliveryVoucher $record) => auth()->user()?->can('approveTechnical', $record))
                ->action(fn (DeliveryVoucher $record) => $this->runApproval(
                    fn () => app(DeliveryVoucherService::class)->approveTechnical($record, auth()->user())
                )),

            Actions\Action::make('approve_financial')
                ->label(__('resources.delivery_vouchers.actions.approve_financial'))
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (DeliveryVoucher $record) => auth()->user()?->can('approveFinancial', $record))
                ->action(fn (DeliveryVoucher $record) => $this->runApproval(
                    fn () => app(DeliveryVoucherService::class)->approveFinancial($record, auth()->user())
                )),

            Actions\Action::make('cancel_voucher')
                ->label(__('resources.delivery_vouchers.actions.cancel'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (DeliveryVoucher $record) => auth()->user()?->can('cancel', $record))
                ->action(fn (DeliveryVoucher $record) => $this->runApproval(
                    fn () => app(DeliveryVoucherService::class)->cancel($record)
                )),

            Actions\DeleteAction::make()
                ->visible(fn (DeliveryVoucher $record) => ! $record->isActive()),
        ];
    }

    private function runApproval(callable $callback): void
    {
        try {
            $callback();
            Notification::make()
                ->title(__('resources.delivery_vouchers.notifications.approved'))
                ->success()
                ->send();

            $this->refreshFormData([
                'status', 'technical_approved_by', 'financial_approved_by', 'total_value',
            ]);
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('resources.common.action_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
