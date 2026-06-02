<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OperationActivated;
use App\Models\User;
use Filament\Notifications\Notification;

/**
 * On activation, notify every department (سلايد 1: "ترحيلها إلى كل الأقسام")
 * via Filament database notifications, so each role sees the new operation in
 * the topbar bell. Recipient roles are configurable in config/operations.php.
 */
class NotifyDepartmentsOfActivation
{
    public function handle(OperationActivated $event): void
    {
        $roles = config('operations.activation_notify_roles', []);

        if (empty($roles)) {
            return;
        }

        // whereHas (not the Spatie role() scope) so unknown role names are
        // simply ignored rather than throwing.
        $recipients = User::whereHas('roles', fn ($query) => $query->whereIn('name', $roles))->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->title(__('resources.operations.notifications.activated_title'))
            ->body(__('resources.operations.notifications.activated_body', [
                'operation' => $event->project->name,
            ]))
            ->icon('heroicon-o-play')
            ->success()
            ->sendToDatabase($recipients);
    }
}
