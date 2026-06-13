<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttachmentCategory;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectOffer;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Slide 5: the system should automatically flag an operation that was added
 * without a (priced) offer. This service is the single source of truth for
 * "what counts as incomplete" — reused by the inline table indicator and by
 * the scheduled notification command.
 *
 * Slide 11 extends it with the inverse, event-driven alerts: notify Sales the
 * moment a financial offer is attached to a pipeline operation (with the
 * running count), and when a submittal is uploaded.
 */
class SalesAlertService
{
    /**
     * Operations still in the Sales pipeline (Tender / In-Hand) that have no
     * offer carrying a price yet.
     *
     * @return Collection<int, Project>
     */
    public function operationsMissingOffers(): Collection
    {
        return Project::query()
            ->whereIn('status', [ProjectStatus::Tender, ProjectStatus::InHand])
            ->missingPricedOffer()
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Slide 11: an automatic alert when a financial offer is attached to an
     * operation still in the Sales pipeline, carrying the running offer count.
     */
    public function notifyOfferAttached(ProjectOffer $offer): void
    {
        $project = $offer->project;

        if ($project === null
            || ! in_array($project->status, [ProjectStatus::Tender, ProjectStatus::InHand], true)) {
            return;
        }

        $recipients = $this->recipientsForRoles(config('operations.offer_notify_roles', []));

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->title(__('resources.sales_alerts.offer_attached_title'))
            ->body(__('resources.sales_alerts.offer_attached_body', [
                'operation' => $project->name,
                'count' => $project->offers()->count(),
            ]))
            ->icon('heroicon-o-banknotes')
            ->info()
            ->sendToDatabase($recipients);
    }

    /**
     * Slide 11: an automatic alert when a submittal file is uploaded for an
     * operation (Sales asked where this lands — now it announces itself).
     */
    public function notifySubmittalUploaded(Project $project): void
    {
        $recipients = $this->recipientsForRoles(config('operations.submittal_notify_roles', []));

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->title(__('resources.sales_alerts.submittal_title'))
            ->body(__('resources.sales_alerts.submittal_body', [
                'operation' => $project->name,
                'count' => $project->attachmentsByCategory(AttachmentCategory::Submittal)->count(),
            ]))
            ->icon('heroicon-o-document-check')
            ->info()
            ->sendToDatabase($recipients);
    }

    /**
     * Users holding any of the given role names. whereHas (not the Spatie
     * role() scope) so unknown role names are ignored rather than throwing.
     *
     * @param  array<int, string>  $roles
     * @return Collection<int, User>
     */
    private function recipientsForRoles(array $roles): Collection
    {
        if (empty($roles)) {
            return collect();
        }

        return User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $roles))
            ->get();
    }
}
