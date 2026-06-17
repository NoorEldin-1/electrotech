<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attachment;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Generic version of the project AttachmentPersistence for any model that
 * exposes a polymorphic `attachments()` morphMany (Supplier, PurchaseOrder).
 * Mirrors the project flow: per-category FileUpload state → concrete Attachment
 * rows, relocating create-time uploads from "{prefix}/new/…" into the owner's
 * own folder, and deleting removed files from disk + DB.
 */
final class EntityAttachmentPersistence
{
    /**
     * @param  array<string, array<int, string>>  $byCategory
     */
    public static function sync(Model $owner, array $byCategory, string $dirPrefix): void
    {
        $disk = Storage::disk('public');
        $pendingPrefix = "{$dirPrefix}/new/";

        foreach ($byCategory as $categoryValue => $filePaths) {
            $resolvedPaths = array_map(
                fn (string $path): string => self::relocatePending($disk, $owner, $dirPrefix, $pendingPrefix, $categoryValue, $path),
                array_map('strval', $filePaths),
            );

            $existing = $owner->attachments()->where('category', $categoryValue)->pluck('file_path');
            $newOnes = collect($resolvedPaths)->diff($existing);
            $removed = $existing->diff(collect($resolvedPaths));

            foreach ($newOnes as $path) {
                $owner->attachments()->create([
                    'file_name' => basename((string) $path),
                    'file_path' => (string) $path,
                    'file_type' => $disk->mimeType((string) $path) ?: null,
                    'file_size' => $disk->exists((string) $path) ? $disk->size((string) $path) : 0,
                    'category' => $categoryValue,
                    'uploaded_by' => Auth::id(),
                ]);
            }

            if ($removed->isNotEmpty()) {
                $owner->attachments()
                    ->where('category', $categoryValue)
                    ->whereIn('file_path', $removed)
                    ->each(function (Attachment $a) use ($disk) {
                        $disk->delete($a->file_path);
                        $a->delete();
                    });
            }
        }
    }

    private static function relocatePending(
        Filesystem $disk,
        Model $owner,
        string $dirPrefix,
        string $pendingPrefix,
        string $categoryValue,
        string $path,
    ): string {
        if (! str_starts_with($path, $pendingPrefix)) {
            return $path;
        }

        $targetDir = "{$dirPrefix}/{$owner->getKey()}/{$categoryValue}";
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
