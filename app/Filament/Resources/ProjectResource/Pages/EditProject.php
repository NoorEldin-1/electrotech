<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Enums\AttachmentCategory;
use App\Enums\ProjectStatus;
use App\Filament\Resources\CustomerInteractionResource;
use App\Filament\Resources\ProjectResource;
use App\Models\Customer;
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
            CustomerInteractionResource::logCommunicationAction(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Keep the denormalized client_name column in sync with the selected
        // customer (see CreateProject for the rationale).
        if (! empty($data['customer_id'])) {
            $data['client_name'] = Customer::find($data['customer_id'])?->name ?? ($data['client_name'] ?? '');
        }

        // The status field is read-only here; a tampered request must never
        // complete an operation behind the gated OperationLifecycleService.
        if (($data['status'] ?? null) === ProjectStatus::Completed->value && $this->record->status !== ProjectStatus::Completed) {
            $data['status'] = $this->record->status?->value;
        }

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
