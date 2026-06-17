<?php

declare(strict_types=1);

namespace App\Filament\Resources\AdditionVoucherResource\Pages;

use App\Enums\AttachmentCategory;
use App\Filament\Concerns\SyncsEntityAttachments;
use App\Filament\Resources\AdditionVoucherResource;
use App\Models\AdditionVoucher;
use App\Services\AdditionVoucherService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditAdditionVoucher extends EditRecord
{
    use SyncsEntityAttachments;

    protected static string $resource = AdditionVoucherResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->pullAttachments($data);
    }

    protected function afterSave(): void
    {
        $this->pushAttachments($this->record);
    }

    protected function attachmentCategories(): array
    {
        return AttachmentCategory::additionVoucherCategories();
    }

    protected function attachmentDirPrefix(): string
    {
        return 'addition-voucher-attachments';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('post')
                ->label(__('resources.addition_vouchers.actions.post'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('resources.addition_vouchers.actions.post_confirm'))
                ->visible(fn (AdditionVoucher $record) => auth()->user()?->can('post', $record))
                ->action(function (AdditionVoucher $record) {
                    try {
                        app(AdditionVoucherService::class)->post($record);
                        Notification::make()
                            ->title(__('resources.addition_vouchers.notifications.posted'))
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
                ->visible(fn (AdditionVoucher $record) => ! $record->isPosted()),
        ];
    }
}
