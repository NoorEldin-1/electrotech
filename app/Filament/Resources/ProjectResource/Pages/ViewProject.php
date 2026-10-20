<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Enums\AttachmentCategory;
use App\Filament\Resources\ProjectResource;
use App\Models\Project;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * The operation file, read-only — for staff who follow an operation but do
 * not own it (PMO timeline, active operations). Whoever holds
 * attachments.upload still adds the project's papers here, without being
 * able to change anything else on the project.
 */
class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),

            Actions\Action::make('addAttachments')
                ->label(__('resources.projects.actions.add_attachments'))
                ->icon('heroicon-o-paper-clip')
                ->modalWidth('3xl')
                ->visible(fn (Project $record): bool => (auth()->user()?->can('attachments.upload') ?? false)
                    && ! (auth()->user()?->can('update', $record) ?? false))
                ->fillForm(fn (Project $record): array => collect(AttachmentCategory::cases())
                    ->mapWithKeys(fn (AttachmentCategory $category): array => [
                        "attachments_{$category->value}" => $record->attachmentsByCategory($category)->pluck('file_path')->all(),
                    ])
                    ->all())
                ->form(ProjectResource::attachmentCategorySchema())
                ->action(function (Project $record, array $data): void {
                    // Add-only: a file cleared from the field here is kept,
                    // since this action is for filing papers, not removing them.
                    $byCategory = [];
                    foreach (AttachmentCategory::cases() as $category) {
                        $key = "attachments_{$category->value}";
                        if (array_key_exists($key, $data)) {
                            $byCategory[$category->value] = array_values(array_unique([
                                ...$record->attachmentsByCategory($category)->pluck('file_path')->all(),
                                ...(array) ($data[$key] ?? []),
                            ]));
                        }
                    }

                    AttachmentPersistence::sync($record, $byCategory);
                    $this->refreshFormData([]);

                    Notification::make()->title(__('resources.projects.notifications.attachments_saved'))->success()->send();
                }),
        ];
    }
}
