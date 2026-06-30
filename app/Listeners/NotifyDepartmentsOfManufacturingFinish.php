<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ManufacturingFinished;
use App\Filament\Resources\WorkOrderResource;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;

/**
 * When manufacturing finishes (التصنيع.pptx سلايد 2), tell every department the
 * product is ready for delivery ("تنبيه بقية الأقسام بأن المنتج جاهز للتسليم").
 * Recipient roles are configurable in config/operations.php.
 *
 * Unlike NotifyDepartmentsOfActivation (which uses ->sendToDatabase()), this
 * writes the bell notification *synchronously* with notifyNow(): Filament's
 * DatabaseNotification implements ShouldQueue, so sendToDatabase() only persists
 * once a queue worker runs — on a host with no worker the bell stays empty.
 * These are cheap inserts that must appear the instant manufacturing is marked
 * done, so we bypass the queue (same rationale as SalesAlertService::sendNow).
 */
class NotifyDepartmentsOfManufacturingFinish
{
    public function handle(ManufacturingFinished $event): void
    {
        $roles = config('operations.manufacturing_finish_notify_roles', []);

        if (empty($roles)) {
            return;
        }

        // whereHas (not the Spatie role() scope) so unknown role names are
        // simply ignored rather than throwing.
        $recipients = User::whereHas('roles', fn ($query) => $query->whereIn('name', $roles))->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $workOrder = $event->workOrder->loadMissing('outputItem');
        $product = $workOrder->outputItem?->name ?? $workOrder->title;

        $notification = Notification::make()
            ->title(__('resources.work_orders.notifications.ready_for_delivery_title'))
            ->body(__('resources.work_orders.notifications.ready_for_delivery_body', [
                'product' => $product,
                'wo_number' => $workOrder->wo_number,
            ]))
            ->icon('heroicon-o-truck')
            ->success()
            ->actions([
                Action::make('view')
                    ->label(__('resources.work_orders.notifications.view_work_order'))
                    ->url(WorkOrderResource::getUrl('edit', ['record' => $workOrder->getKey()], panel: 'admin'))
                    ->markAsRead(),
            ]);

        foreach ($recipients as $recipient) {
            $recipient->notifyNow($notification->toDatabase());
        }
    }
}
