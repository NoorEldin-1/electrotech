<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Models\Attachment;
use App\Models\Project;
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
     * @param  array<string, array<int, string>>  $byCategory
     */
    public static function sync(Project $project, array $byCategory): void
    {
        foreach ($byCategory as $categoryValue => $filePaths) {
            $existing = $project->attachments()->where('category', $categoryValue)->pluck('file_path');
            $newOnes = collect($filePaths)->diff($existing);
            $removed = $existing->diff(collect($filePaths));

            foreach ($newOnes as $path) {
                $project->attachments()->create([
                    'file_name' => basename((string) $path),
                    'file_path' => (string) $path,
                    'file_type' => Storage::disk('public')->mimeType((string) $path) ?: null,
                    'file_size' => Storage::disk('public')->exists((string) $path)
                        ? Storage::disk('public')->size((string) $path)
                        : 0,
                    'category' => $categoryValue,
                    'uploaded_by' => Auth::id(),
                ]);
            }

            if ($removed->isNotEmpty()) {
                $project->attachments()
                    ->where('category', $categoryValue)
                    ->whereIn('file_path', $removed)
                    ->each(function (Attachment $a) {
                        Storage::disk('public')->delete($a->file_path);
                        $a->delete();
                    });
            }
        }
    }
}
