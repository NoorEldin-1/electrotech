<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SalesAlertService;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Slide 5: an automatic alert when an operation is sitting in the pipeline
 * without a priced offer. Runs on a schedule and drops a database
 * notification (the topbar bell) on the Sales team — mirrors the
 * NotifyDepartmentsOfActivation pattern.
 */
class NotifyIncompleteOperations extends Command
{
    protected $signature = 'sales:notify-incomplete-operations';

    protected $description = 'Notify Sales of pipeline operations that have no priced offer yet.';

    public function handle(SalesAlertService $alerts): int
    {
        $missing = $alerts->operationsMissingOffers();

        if ($missing->isEmpty()) {
            $this->info('No incomplete operations.');

            return self::SUCCESS;
        }

        $recipients = User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['Sales', 'Sales_Manager']))
            ->get();

        if ($recipients->isEmpty()) {
            return self::SUCCESS;
        }

        Notification::make()
            ->title(__('resources.sales_alerts.notification_title'))
            ->body(__('resources.sales_alerts.notification_body', ['count' => $missing->count()]))
            ->icon('heroicon-o-exclamation-triangle')
            ->warning()
            ->sendToDatabase($recipients);

        $this->info("Notified {$recipients->count()} user(s) about {$missing->count()} incomplete operation(s).");

        return self::SUCCESS;
    }
}
