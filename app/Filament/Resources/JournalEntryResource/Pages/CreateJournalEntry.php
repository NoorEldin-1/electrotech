<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntryResource\Pages;

use App\Enums\DocumentType;
use App\Filament\Resources\JournalEntryResource;
use App\Models\JournalEntry;
use App\Services\JournalEntryService;
use Filament\Resources\Pages\CreateRecord;

class CreateJournalEntry extends CreateRecord
{
    protected static string $resource = JournalEntryResource::class;

    /** @var array<int, array<string, mixed>> */
    protected array $debitLines = [];

    /** @var array<int, array<string, mixed>> */
    protected array $creditLines = [];

    /**
     * The list page links here with the document type already chosen, so the
     * treasury side is filled in before the accountant types anything.
     */
    protected function afterFill(): void
    {
        foreach (JournalEntryResource::treasuryStateChanges($this->data) as $path => $value) {
            data_set($this->data, $path, $value);
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->debitLines = $data['debit_lines'] ?? [];
        $this->creditLines = $data['credit_lines'] ?? [];

        unset($data['debit_lines'], $data['credit_lines'], $data['show_line_details']);

        $type = $data['document_type'] instanceof DocumentType
            ? $data['document_type']
            : DocumentType::from($data['document_type']);

        $data['entry_number'] = JournalEntry::generateEntryNumber($type);
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        // The lines live in their own table; writing them here also caches the
        // debit/credit totals so the list reflects them for drafts too.
        app(JournalEntryService::class)->syncLines($this->record, $this->debitLines, $this->creditLines);
    }

    /**
     * Back to the list after saving — the platform-wide rule (E2E report
     * §5.3): Filament's default lands a create on the record's Edit page,
     * which made "what happens after save" differ from module to module.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
