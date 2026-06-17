<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\AttachmentCategory;
use Filament\Forms;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds the per-category FileUpload components used by the Supplier and
 * Purchase Order attachment sections (slides 3 & 6) — the same shape as the
 * project attachments, but for any owner exposing a polymorphic
 * `attachments()` relation. Pair with App\Services\EntityAttachmentPersistence
 * and the SyncsEntityAttachments page trait.
 */
final class EntityAttachments
{
    /**
     * @param  array<int, AttachmentCategory>  $categories
     * @return array<int, Forms\Components\FileUpload>
     */
    public static function fileUploads(array $categories, string $dirPrefix): array
    {
        $components = [];

        foreach ($categories as $category) {
            $components[] = Forms\Components\FileUpload::make("attachments_{$category->value}")
                ->label(__('resources.enums.attachment_category.' . $category->value))
                ->multiple()
                ->preserveFilenames()
                ->downloadable()
                ->openable()
                ->previewable(false)
                ->maxSize(40960)
                ->disk('public')
                ->directory(fn (?Model $record) => $dirPrefix . '/' . ($record?->getKey() ?? 'new') . '/' . $category->value)
                ->afterStateHydrated(function (Forms\Components\FileUpload $component, ?Model $record) use ($category) {
                    if ($record === null) {
                        return;
                    }

                    $component->state(
                        $record->attachments()
                            ->where('category', $category->value)
                            ->pluck('file_path')
                            ->all()
                    );
                });
        }

        return $components;
    }
}
