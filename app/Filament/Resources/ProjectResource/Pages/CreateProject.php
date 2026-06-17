<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Enums\AttachmentCategory;
use App\Filament\Resources\ProjectResource;
use App\Models\Customer;
use Filament\Resources\Pages\CreateRecord;

class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    /**
     * Pull the per-category FileUpload state out of the form payload
     * before Eloquent's mass-assignment, because those keys are virtual
     * UI fields (attachments_upload / attachments_boq / …) and don't
     * exist on the projects table.
     *
     * We stash them on a class property so afterCreate() can persist
     * the Attachment rows once we have a project id.
     *
     * @var array<string, array<int, string>>
     */
    protected array $pendingAttachmentsByCategory = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Keep the denormalized client_name column in sync with the selected
        // customer so the project lists, offer PDF and reports (which still read
        // client_name) reflect the chosen customer.
        if (! empty($data['customer_id'])) {
            $data['client_name'] = Customer::find($data['customer_id'])?->name ?? ($data['client_name'] ?? '');
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

    protected function afterCreate(): void
    {
        AttachmentPersistence::sync($this->record, $this->pendingAttachmentsByCategory);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
