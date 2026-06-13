<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Enums\AttachmentCategory;
use App\Models\Attachment;
use App\Models\Project;
use App\Services\SalesAlertService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Shared logic between CreateProject and EditProject for syncing the
 * per-category FileUpload state to concrete Attachment rows. The two
 * Page classes can't inherit a common base because Filament's
 * CreateRecord and EditRecord are separate parents — so we extract
 * the persistence step into a static helper.
 */
final class AttachmentPersistence
{
    /**
     * Files uploaded on the Create screen are written before the project has
     * an id, so Filament stores them under this shared prefix. Left there,
     * two different projects uploading a same-named file to the same category
     * would overwrite each other on disk — so we relocate them into the
     * project's own folder on persist.
     */
    private const PENDING_PREFIX = 'attachments/new/';

    /**
     * @param  array<string, array<int, string>>  $byCategory
     */
    public static function sync(Project $project, array $byCategory): void
    {
        $disk = Storage::disk('public');
        $submittalAdded = false;

        foreach ($byCategory as $categoryValue => $filePaths) {
            // Move any not-yet-scoped uploads into attachments/{id}/{category}
            // before we compare against what's already stored.
            $resolvedPaths = array_map(
                fn (string $path): string => self::relocatePending($disk, $project, $categoryValue, $path),
                array_map('strval', $filePaths),
            );

            $existing = $project->attachments()->where('category', $categoryValue)->pluck('file_path');
            $newOnes = collect($resolvedPaths)->diff($existing);
            $removed = $existing->diff(collect($resolvedPaths));

            foreach ($newOnes as $path) {
                $project->attachments()->create([
                    'file_name' => basename((string) $path),
                    'file_path' => (string) $path,
                    'file_type' => $disk->mimeType((string) $path) ?: null,
                    'file_size' => $disk->exists((string) $path) ? $disk->size((string) $path) : 0,
                    'category' => $categoryValue,
                    'uploaded_by' => Auth::id(),
                ]);
            }

            // Slide 11: announce a freshly-uploaded submittal once the loop ends.
            if ($categoryValue === AttachmentCategory::Submittal->value && $newOnes->isNotEmpty()) {
                $submittalAdded = true;
            }

            if ($removed->isNotEmpty()) {
                $project->attachments()
                    ->where('category', $categoryValue)
                    ->whereIn('file_path', $removed)
                    ->each(function (Attachment $a) use ($disk) {
                        $disk->delete($a->file_path);
                        $a->delete();
                    });
            }
        }

        if ($submittalAdded) {
            app(SalesAlertService::class)->notifySubmittalUploaded($project);
        }
    }

    /**
     * Move a file uploaded under attachments/new/… into the project's own
     * folder, returning the final path. Paths that are already project-scoped
     * (uploads added from the Edit screen) are returned untouched. A name
     * clash inside the project's folder is resolved with a numeric suffix so
     * no existing file is ever clobbered.
     */
    private static function relocatePending(Filesystem $disk, Project $project, string $categoryValue, string $path): string
    {
        if (! str_starts_with($path, self::PENDING_PREFIX)) {
            return $path;
        }

        $targetDir = "attachments/{$project->id}/{$categoryValue}";
        $filename = basename($path);
        $target = "{$targetDir}/{$filename}";

        if ($target !== $path && $disk->exists($target)) {
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $suffix = $ext !== '' ? ".{$ext}" : '';
            $i = 1;
            do {
                $target = "{$targetDir}/{$name}-{$i}{$suffix}";
                $i++;
            } while ($disk->exists($target));
        }

        if ($disk->exists($path)) {
            $disk->makeDirectory($targetDir);
            $disk->move($path, $target);
        }

        return $target;
    }
}
