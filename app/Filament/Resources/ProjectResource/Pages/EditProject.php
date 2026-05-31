<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Enums\AttachmentCategory;
use App\Filament\Resources\ProjectResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    /**
     * @var array<string, array<int, string>>
     */
    protected array $pendingAttachmentsByCategory = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach (AttachmentCategory::cases() as $category) {
            $key = "attachments_{$category->value}";
            if (array_key_exists($key, $data)) {
                $this->pendingAttachmentsByCategory[$category->value] = (array) ($data[$key] ?? []);
                unset($data[$key]);
            }
        }

        return $data;
    }

    protected function afterSave(): void
    {
        AttachmentPersistence::sync($this->record, $this->pendingAttachmentsByCategory);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
