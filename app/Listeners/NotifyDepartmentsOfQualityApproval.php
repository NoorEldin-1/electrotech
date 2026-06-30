<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\QualitySheetApproved;
use App\Filament\Resources\QualitySheetResource;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;

/**
 * When the factory manager approves a quality sheet (التصنيع سلايد 3), tell every
 * department the operation has finished manufacturing ("تنبيه لجميع الأقسام أن
 * العملية تم الانتهاء من تصنيعها"). Recipient roles are configurable in
 * config/operations.php.
 *
 * Writes the bell notification *synchronously* with notifyNow() for the same
 * reason as NotifyDepartmentsOfManufacturingFinish: Filament's
 * DatabaseNotification implements ShouldQueue, so sendToDatabase() would only
 * persist once a queue worker runs. These cheap inserts must appear the instant
 * the sheet is approved, so we bypass the queue.
 */
class NotifyDepartmentsOfQualityApproval
{
    public function handle(QualitySheetApproved $event): void
    {
        $roles = config('operations.quality_approval_notify_roles', []);

        if (empty($roles)) {
            return;
        }

        // whereHas (not the Spatie role() scope) so unknown role names are
        // simply ignored rather than throwing.
        $recipients = User::whereHas('roles', fn ($query) => $query->whereIn('name', $roles))->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $sheet = $event->qualitySheet->loadMissing('workOrder');
        $woNumber = $sheet->workOrder?->wo_number ?? '—';

        $notification = Notification::make()
            ->title(__('resources.quality_sheets.notifications.approved_alert_title'))
            ->body(__('resources.quality_sheets.notifications.approved_alert_body', [
                'sheet_number' => $sheet->sheet_number,
                'wo_number' => $woNumber,
            ]))
            ->icon('heroicon-o-shield-check')
            ->success()
            ->actions([
                Action::make('view')
                    ->label(__('resources.quality_sheets.notifications.view_quality_sheet'))
                    ->url(QualitySheetResource::getUrl('edit', ['record' => $sheet->getKey()], panel: 'admin'))
                    ->markAsRead(),
            ]);

        foreach ($recipients as $recipient) {
            $recipient->notifyNow($notification->toDatabase());
        }
    }
}
