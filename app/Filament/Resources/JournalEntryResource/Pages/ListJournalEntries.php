<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntryResource\Pages;

use App\Enums\DocumentType;
use App\Filament\Resources\JournalEntryResource;
use App\Models\JournalEntry;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListJournalEntries extends ListRecords
{
    protected static string $resource = JournalEntryResource::class;

    /**
     * The document type is the first thing the accountant knows about a new
     * entry — أمر صرف, إيصال توريد or قيد تسوية — so it is chosen here and the
     * create form opens with it already set (and the treasury already on the
     * right side).
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\ActionGroup::make(
                array_map(
                    fn (DocumentType $type): Actions\Action => Actions\Action::make($type->value)
                        ->label($type->getLabel())
                        ->icon('heroicon-o-document-text')
                        ->color($type->getColor())
                        ->url(JournalEntryResource::getUrl('create', ['document_type' => $type->value])),
                    DocumentType::cases(),
                ),
            )
                ->label(__('resources.journal_entries.actions.create'))
                ->icon('heroicon-o-plus')
                ->button()
                ->color('primary')
                ->visible(fn (): bool => auth()->user()?->can('create', JournalEntry::class) ?? false),
        ];
    }
}
