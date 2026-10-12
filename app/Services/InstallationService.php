<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttachmentCategory;
use App\Enums\InstallationStatus;
use App\Models\Attachment;
use App\Models\Installation;
use App\Models\Project;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * مرحلة التركيب — drives the installation stage (سلايد 2): pending → in_progress
 * → completed. Installation expenses are loaded onto the cost center via GL
 * (account 5020 tagged to the operation), not here.
 *
 * Presentation.pptx سلايد 8: «انتهاء التركيب (عن طريق محاضر تسليم الاعمال)» —
 * an installation is finished BY the works-handover minutes. Completing one
 * therefore requires the minutes on the operation file, and uploading the
 * minutes completes whatever installation is still running (see
 * AttachmentObserver), from the panel and the API alike.
 */
class InstallationService
{
    public function start(Installation $installation): void
    {
        $this->assertStatus($installation, [InstallationStatus::Pending]);

        $installation->update([
            'status' => InstallationStatus::InProgress,
            'started_at' => now(),
        ]);
    }

    /**
     * @param  string|null  $handoverMinutesPath  a file already stored on the public disk; attached to the operation as محاضر التسليم before completing
     */
    public function complete(Installation $installation, ?string $handoverMinutesPath = null): void
    {
        $this->assertStatus($installation, [InstallationStatus::InProgress]);

        if ($handoverMinutesPath !== null) {
            // Attaching the minutes completes the running installations of the
            // operation through the observer — this one included.
            $this->attachHandoverMinutes($installation->project, $handoverMinutesPath);

            if ($installation->refresh()->isCompleted()) {
                return;
            }
        }

        if (! $this->hasHandoverMinutes($installation->project_id)) {
            throw new DomainException(__('errors.installations.handover_minutes_required'));
        }

        $installation->update([
            'status' => InstallationStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    public function hasHandoverMinutes(int $projectId): bool
    {
        return Attachment::query()
            ->where('project_id', $projectId)
            ->where('category', AttachmentCategory::HandoverMinutes->value)
            ->exists();
    }

    /**
     * Uploading محاضر تسليم الأعمال finishes every installation of the
     * operation that is still in progress. Returns how many were completed.
     */
    public function completeFromHandoverMinutes(Attachment $minutes): int
    {
        if ($minutes->project_id === null) {
            return 0;
        }

        $running = Installation::query()
            ->where('project_id', $minutes->project_id)
            ->where('status', InstallationStatus::InProgress->value)
            ->get();

        foreach ($running as $installation) {
            $installation->update([
                'status' => InstallationStatus::Completed,
                'completed_at' => now(),
            ]);
        }

        return $running->count();
    }

    /**
     * @param  string|null  $displayName  the client's original filename, shown in the UI only — never part of the stored path
     */
    public function attachHandoverMinutes(Project $project, string $path, ?string $displayName = null): Attachment
    {
        $disk = Storage::disk('public');

        return $project->attachments()->create([
            'file_name' => $displayName ?? basename($path),
            'file_path' => $path,
            'file_type' => $disk->exists($path) ? ($disk->mimeType($path) ?: null) : null,
            'file_size' => $disk->exists($path) ? $disk->size($path) : 0,
            'category' => AttachmentCategory::HandoverMinutes->value,
            'uploaded_by' => Auth::id(),
        ]);
    }

    /**
     * @param  array<int, InstallationStatus>  $allowed
     */
    private function assertStatus(Installation $installation, array $allowed): void
    {
        if (! in_array($installation->status, $allowed, true)) {
            throw new DomainException(__('errors.operations.illegal_transition', [
                'current' => $installation->status->value,
                'allowed' => implode(', ', array_map(fn (InstallationStatus $s) => $s->value, $allowed)),
            ]));
        }
    }
}
