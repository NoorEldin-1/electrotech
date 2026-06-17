<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Services\EntityAttachmentPersistence;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared Create/Edit page glue for entity (Supplier / PurchaseOrder)
 * attachments. The `attachments_{category}` FileUpload fields are virtual
 * (no column), so they're pulled out of the form data before the model is
 * written and synced to Attachment rows afterwards. The host page must declare
 * `attachmentCategories()` and `attachmentDirPrefix()`.
 */
trait SyncsEntityAttachments
{
    /** @var array<string, array<int, string>> */
    protected array $pendingAttachmentsByCategory = [];

    protected function pullAttachments(array $data): array
    {
        foreach ($this->attachmentCategories() as $category) {
            $key = "attachments_{$category->value}";
            if (array_key_exists($key, $data)) {
                $this->pendingAttachmentsByCategory[$category->value] = (array) ($data[$key] ?? []);
                unset($data[$key]);
            }
        }

        return $data;
    }

    protected function pushAttachments(Model $owner): void
    {
        EntityAttachmentPersistence::sync($owner, $this->pendingAttachmentsByCategory, $this->attachmentDirPrefix());
    }

    /**
     * @return array<int, \App\Enums\AttachmentCategory>
     */
    abstract protected function attachmentCategories(): array;

    abstract protected function attachmentDirPrefix(): string;
}
