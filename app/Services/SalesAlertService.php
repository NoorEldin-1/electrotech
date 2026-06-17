<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttachmentCategory;
use App\Enums\ProjectStatus;
use App\Filament\Resources\ProjectResource;
use App\Models\Project;
use App\Models\ProjectOffer;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
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
 *
 * On top of those it keeps a set of *self-clearing error alerts* in the topbar
 * bell: a red alert per Tender operation with no offer and per In-Hand
 * operation with no SMB. Each alert links straight to the operation, and is
 * removed automatically the moment the gap is filled (offer added / SMB
 * uploaded / the operation leaves the stage) — so the bell always mirrors the
 * live state, never a stale warning.
 */
class SalesAlertService
{
    /** Bell-alert kinds, stored in the notification's viewData so we can find them again. */
    public const ALERT_MISSING_OFFER = 'missing_offer';

    public const ALERT_MISSING_SMB = 'missing_smb';

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
     * Tender operations (المناقصات) that still carry no priced offer — the
     * trigger for the "missing offer" bell alert.
     *
     * @return Collection<int, Project>
     */
    public function tendersMissingOffer(): Collection
    {
        return Project::query()
            ->tender()
            ->missingPricedOffer()
            ->orderBy('created_at')
            ->get();
    }

    /**
     * In-Hand operations (العمليات تحت اليد) with no SMB / Submittal file on
     * record — the trigger for the "missing SMB" bell alert. SMB and Submittal
     * are one artifact, so this reads the Submittal attachments.
     *
     * @return Collection<int, Project>
     */
    public function inHandMissingSmb(): Collection
    {
        return Project::query()
            ->inHand()
            ->whereDoesntHave('attachments', fn (Builder $q) => $q->where('category', AttachmentCategory::Submittal->value))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Rebuild the bell-alert set so it matches the live pipeline state: raise a
     * red alert for every Tender with no offer and every In-Hand with no SMB,
     * and drop any alert whose operation no longer qualifies. Idempotent — safe
     * to run on a schedule and after every pipeline transition.
     */
    public function reconcileOperationAlerts(): void
    {
        $this->syncAlertType(
            self::ALERT_MISSING_OFFER,
            config('operations.offer_notify_roles', []),
            $this->tendersMissingOffer(),
        );

        $this->syncAlertType(
            self::ALERT_MISSING_SMB,
            config('operations.submittal_notify_roles', []),
            $this->inHandMissingSmb(),
        );
    }

    /**
     * Remove the bell alert of a given kind for one operation across every
     * recipient — used the instant the gap is filled (priced offer attached,
     * SMB uploaded) so the warning disappears without waiting for the schedule.
     */
    public function clearOperationAlert(Project $project, string $type): void
    {
        DatabaseNotification::query()
            ->where('data->viewData->alert_type', $type)
            ->where('data->viewData->project_id', $project->getKey())
            ->delete();
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

        // Self-healing: a priced offer is now on file, so the "missing offer"
        // error alert for this operation is obsolete — pull it from the bell.
        if ($project->hasPricedOffer()) {
            $this->clearOperationAlert($project, self::ALERT_MISSING_OFFER);
        }

        $recipients = $this->recipientsForRoles(config('operations.offer_notify_roles', []));

        if ($recipients->isEmpty()) {
            return;
        }

        $this->sendNow(
            Notification::make()
                ->title(__('resources.sales_alerts.offer_attached_title'))
                ->body(__('resources.sales_alerts.offer_attached_body', [
                    'operation' => $project->name,
                    'count' => $project->offers()->count(),
                ]))
                ->icon('heroicon-o-banknotes')
                ->info(),
            $recipients,
        );
    }

    /**
     * Slide 11: an automatic alert when a submittal file is uploaded for an
     * operation (Sales asked where this lands — now it announces itself).
     */
    public function notifySubmittalUploaded(Project $project): void
    {
        // Self-healing: the SMB / Submittal is now on file, so the "missing
        // SMB" error alert for this operation is obsolete — pull it from the bell.
        $this->clearOperationAlert($project, self::ALERT_MISSING_SMB);

        $recipients = $this->recipientsForRoles(config('operations.submittal_notify_roles', []));

        if ($recipients->isEmpty()) {
            return;
        }

        $this->sendNow(
            Notification::make()
                ->title(__('resources.sales_alerts.submittal_title'))
                ->body(__('resources.sales_alerts.submittal_body', [
                    'operation' => $project->name,
                    'count' => $project->attachmentsByCategory(AttachmentCategory::Submittal)->count(),
                ]))
                ->icon('heroicon-o-document-check')
                ->info(),
            $recipients,
        );
    }

    /**
     * Bring one recipient group's bell alerts of a given kind in line with the
     * set of operations that should currently carry one: delete the stale,
     * create the missing, leave the rest untouched (so a read alert is not
     * re-raised as unread on every pass).
     *
     * @param  array<int, string>  $roles
     * @param  Collection<int, Project>  $projects
     */
    private function syncAlertType(string $type, array $roles, Collection $projects): void
    {
        $recipients = $this->recipientsForRoles($roles);

        if ($recipients->isEmpty()) {
            return;
        }

        $projectsById = $projects->keyBy(fn (Project $p): int => (int) $p->getKey());
        $desiredIds = $projectsById->keys()->all();

        foreach ($recipients as $recipient) {
            $existing = $recipient->notifications()
                ->where('data->viewData->alert_type', $type)
                ->get()
                ->keyBy(fn (DatabaseNotification $n): int => (int) ($n->data['viewData']['project_id'] ?? 0));

            foreach ($existing as $projectId => $notification) {
                if (! in_array($projectId, $desiredIds, true)) {
                    $notification->delete();
                }
            }

            foreach ($projectsById as $projectId => $project) {
                if (! $existing->has($projectId)) {
                    $this->sendOperationAlert($recipient, $type, $project);
                }
            }
        }
    }

    /**
     * Drop a single red bell alert on one recipient, tagged (via viewData) so
     * it can be found and cleared later, and wired to open the operation.
     */
    private function sendOperationAlert(User $recipient, string $type, Project $project): void
    {
        $content = $this->alertContent($type, $project);

        $notification = Notification::make()
            ->title($content['title'])
            ->body($content['body'])
            ->icon('heroicon-o-exclamation-triangle')
            ->danger()
            ->viewData(['alert_type' => $type, 'project_id' => (int) $project->getKey()])
            ->actions([
                Action::make('view')
                    ->label(__('resources.sales_alerts.view_operation'))
                    ->url(ProjectResource::getUrl('edit', ['record' => $project->getKey()], panel: 'admin'))
                    ->markAsRead(),
            ]);

        $this->sendNow($notification, [$recipient]);
    }

    /**
     * Write a Filament notification to each recipient's bell *synchronously*.
     *
     * Filament's DatabaseNotification implements ShouldQueue, so the usual
     * ->sendToDatabase() only persists the row once a queue worker runs the
     * job — on a host with no worker (a common production setup) the bell would
     * stay empty. These are cheap inserts that must appear the instant they are
     * raised, so we bypass the queue with notifyNow(): the bell then works on
     * any host, worker or not.
     *
     * @param  iterable<int, User>  $recipients
     */
    private function sendNow(Notification $notification, iterable $recipients): void
    {
        foreach ($recipients as $recipient) {
            $recipient->notifyNow($notification->toDatabase());
        }
    }

    /**
     * @return array{title: string, body: string}
     */
    private function alertContent(string $type, Project $project): array
    {
        return match ($type) {
            self::ALERT_MISSING_OFFER => [
                'title' => __('resources.sales_alerts.missing_offer_alert_title'),
                'body' => __('resources.sales_alerts.missing_offer_alert_body', ['operation' => $project->name]),
            ],
            self::ALERT_MISSING_SMB => [
                'title' => __('resources.sales_alerts.missing_smb_alert_title'),
                'body' => __('resources.sales_alerts.missing_smb_alert_body', ['operation' => $project->name]),
            ],
            default => ['title' => '', 'body' => ''],
        };
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
