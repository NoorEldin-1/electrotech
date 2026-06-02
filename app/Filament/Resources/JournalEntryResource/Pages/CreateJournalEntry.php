<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntryResource\Pages;

use App\Enums\DocumentType;
use App\Filament\Resources\JournalEntryResource;
use App\Models\JournalEntry;
use Filament\Resources\Pages\CreateRecord;

class CreateJournalEntry extends CreateRecord
{
    protected static string $resource = JournalEntryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $type = $data['document_type'] instanceof DocumentType
            ? $data['document_type']
            : DocumentType::from($data['document_type']);

        $data['entry_number'] = JournalEntry::generateEntryNumber($type);
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        // Lines are saved by the relationship repeater after the parent; cache
        // the debit/credit totals so the table reflects them for drafts too.
        $this->record->recalculateTotals();
    }
}
