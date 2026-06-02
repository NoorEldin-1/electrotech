<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DeliveryMinute;
use App\Models\DeliveryVoucher;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * محاضر التسليم — generates a delivery minute from a delivery and distributes
 * it to all departments (سلايد 2: "محاضر التسليم وارسالها لجميع الأقسام").
 */
class DeliveryMinuteService
{
    /**
     * Create a delivery minute from a delivery voucher, inheriting its
     * operation and customer.
     */
    public function generateFromDelivery(DeliveryVoucher $voucher, ?string $content = null): DeliveryMinute
    {
        return DeliveryMinute::create([
            'project_id' => $voucher->project_id,
            'delivery_voucher_id' => $voucher->id,
            'customer_id' => $voucher->customer_id,
            'minute_date' => now()->toDateString(),
            'content' => $content,
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * Distribute the minute to all departments via database notifications and
     * stamp distributed_at. Idempotent — already-distributed minutes are not
     * re-sent.
     */
    public function distribute(DeliveryMinute $minute): void
    {
        if ($minute->isDistributed()) {
            return;
        }

        $minute->update(['distributed_at' => now()]);

        $roles = config('operations.activation_notify_roles', []);

        if (empty($roles)) {
            return;
        }

        $recipients = User::whereHas('roles', fn ($query) => $query->whereIn('name', $roles))->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $minute->loadMissing('project');

        Notification::make()
            ->title(__('resources.delivery_minutes.notifications.distributed_title'))
            ->body(__('resources.delivery_minutes.notifications.distributed_body', [
                'number' => $minute->minute_number,
                'operation' => $minute->project?->name ?? '',
            ]))
            ->icon('heroicon-o-document-check')
            ->success()
            ->sendToDatabase($recipients);
    }
}
