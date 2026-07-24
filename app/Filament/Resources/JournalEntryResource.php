<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\AccountDirection;
use App\Enums\DocumentType;
use App\Enums\JournalStatus;
use App\Filament\Resources\JournalEntryResource\Pages;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\JournalEntryService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * قيود اليومية — manual double-entry journal (سلايد 2). Each entry carries a
 * colour-coded document type and a set of debit/credit lines that must balance
 * before it can be posted.
 */
class JournalEntryResource extends Resource
{
    protected static ?string $model = JournalEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?int $navigationSort = 58;

    protected static ?string $recordTitleAttribute = 'entry_number';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getLabel(): string
    {
        return __('resources.journal_entries.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.journal_entries.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.journal_entries.navigation_label');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('resources.journal_entries.sections.details'))
                    ->icon('heroicon-o-book-open')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('entry_serial')
                            ->label(__('resources.journal_entries.fields.entry_serial'))
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder(__('resources.common.auto_generated')),

                        Forms\Components\TextInput::make('entry_number')
                            ->label(__('resources.journal_entries.fields.entry_number'))
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder(__('resources.common.auto_generated')),

                        Forms\Components\Select::make('document_type')
                            ->label(__('resources.journal_entries.fields.document_type'))
                            ->options(DocumentType::class)
                            ->required(),

                        // Left blank, the model assigns the next number in this
                        // document type's own sequence; typed over, it matches
                        // the physical voucher.
                        Forms\Components\TextInput::make('document_number')
                            ->label(__('resources.journal_entries.fields.document_number'))
                            ->helperText(__('resources.journal_entries.helpers.document_number'))
                            ->placeholder(__('resources.common.auto_generated'))
                            ->maxLength(100),

                        Forms\Components\DatePicker::make('entry_date')
                            ->label(__('resources.journal_entries.fields.entry_date'))
                            ->default(now())
                            ->required(),

                        Forms\Components\TextInput::make('description')
                            ->label(__('resources.journal_entries.fields.description'))
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Forms\Components\Select::make('currency')
                            ->label(__('resources.journal_entries.fields.currency'))
                            ->options([
                                'EGP' => 'EGP',
                                'USD' => 'USD',
                                'EUR' => 'EUR',
                            ])
                            ->default('EGP')
                            ->required(),

                        Forms\Components\Textarea::make('notes')
                            ->label(__('resources.journal_entries.fields.notes'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make(__('resources.journal_entries.sections.lines'))
                    ->icon('heroicon-o-list-bullet')
                    ->schema([
                        Forms\Components\Repeater::make('lines')
                            ->label(__('resources.journal_entries.fields.lines'))
                            ->relationship()
                            ->columns(3)
                            ->defaultItems(2)
                            ->minItems(2)
                            ->schema([
                                Forms\Components\Select::make('account_id')
                                    ->label(__('resources.journal_entries.fields.account'))
                                    ->relationship(
                                        name: 'account',
                                        titleAttribute: 'name',
                                        modifyQueryUsing: fn (Builder $query) => $query->where('is_active', true)->orderBy('code'),
                                    )
                                    ->getOptionLabelFromRecordUsing(fn (Account $record): string => $record->display_name)
                                    ->searchable(['code', 'name'])
                                    ->preload()
                                    ->required(),

                                // Optional cost-center tag (الإدارة العامة):
                                // attach this line's expense to an operation so
                                // the Operation Cost Center can aggregate it.
                                Forms\Components\Select::make('project_id')
                                    ->label(__('resources.journal_entries.fields.project'))
                                    ->relationship(name: 'project', titleAttribute: 'name')
                                    ->searchable(['name', 'code'])
                                    ->preload()
                                    ->nullable(),

                                Forms\Components\Select::make('direction')
                                    ->label(__('resources.journal_entries.fields.direction'))
                                    ->options(AccountDirection::class)
                                    ->required()
                                    ->live(),

                                Forms\Components\TextInput::make('amount')
                                    ->label(__('resources.journal_entries.fields.amount'))
                                    ->numeric()
                                    ->minValue(0.01)
                                    ->required()
                                    ->live(onBlur: true),

                                Forms\Components\TextInput::make('line_notes')
                                    ->label(__('resources.journal_entries.fields.line_notes'))
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                            ])
                            ->disabled(fn (?JournalEntry $record) => $record?->isPosted() ?? false)
                            ->columnSpanFull(),

                        Forms\Components\Placeholder::make('totals')
                            ->label(__('resources.journal_entries.fields.totals'))
                            ->content(fn (Forms\Get $get): HtmlString => static::renderTotals($get('lines') ?? [])),
                    ]),
            ]);
    }

    /**
     * Live debit/credit totals + difference, shown under the lines repeater so
     * the user can see the entry balance before posting.
     */
    protected static function renderTotals(array $lines): HtmlString
    {
        $debit = 0.0;
        $credit = 0.0;

        foreach ($lines as $line) {
            $amount = (float) ($line['amount'] ?? 0);
            if (($line['direction'] ?? null) === AccountDirection::Debit->value) {
                $debit += $amount;
            } elseif (($line['direction'] ?? null) === AccountDirection::Credit->value) {
                $credit += $amount;
            }
        }

        $diff = round($debit - $credit, 2);
        $diffColor = abs($diff) < 0.001 ? 'color:#059669;' : 'color:#e11d48;';

        return new HtmlString(
            '<div class="text-sm">'
            . '<div>' . e(__('resources.journal_entries.placeholders.total_debit')) . ': <strong>' . number_format($debit, 2) . '</strong></div>'
            . '<div>' . e(__('resources.journal_entries.placeholders.total_credit')) . ': <strong>' . number_format($credit, 2) . '</strong></div>'
            . '<div style="' . $diffColor . '">' . e(__('resources.journal_entries.placeholders.difference')) . ': <strong>' . number_format($diff, 2) . '</strong></div>'
            . '</div>'
        );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('entry_date')
                    ->label(__('resources.journal_entries.columns.entry_date'))
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('entry_serial')
                    ->label(__('resources.journal_entries.columns.entry_serial'))
                    ->numeric()
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('entry_number')
                    ->label(__('resources.journal_entries.columns.entry_number'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('document_type')
                    ->label(__('resources.journal_entries.columns.document_type'))
                    ->badge()
                    ->sortable(),

                // The document number is printed in its type's colour, the way
                // the client's daybook reads it: black = payment order,
                // red = supply receipt, green = settlement.
                Tables\Columns\TextColumn::make('document_number')
                    ->label(__('resources.journal_entries.columns.document_number'))
                    ->weight('bold')
                    ->color(fn (JournalEntry $record) => $record->document_type->getColor())
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('description')
                    ->label(__('resources.journal_entries.columns.description'))
                    ->limit(40)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total_debit')
                    ->label(__('resources.journal_entries.columns.total_debit'))
                    ->money(fn (JournalEntry $record): string => $record->currency)
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_credit')
                    ->label(__('resources.journal_entries.columns.total_credit'))
                    ->money(fn (JournalEntry $record): string => $record->currency)
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('resources.journal_entries.columns.status'))
                    ->badge()
                    ->sortable(),
            ])
            ->defaultSort('entry_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('document_type')
                    ->label(__('resources.journal_entries.columns.document_type'))
                    ->options(DocumentType::class),

                Tables\Filters\SelectFilter::make('status')
                    ->label(__('resources.journal_entries.columns.status'))
                    ->options(JournalStatus::class),

                Tables\Filters\Filter::make('entry_date')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label(__('resources.journal_entries.filters.from')),
                        Forms\Components\DatePicker::make('until')
                            ->label(__('resources.journal_entries.filters.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('entry_date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('entry_date', '<=', $d))),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    static::postAction(),
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make()
                        ->visible(fn (JournalEntry $record) => $record->isDraft()),
                ])
                    ->tooltip(__('resources.common.actions')),
            ]);
    }

    /**
     * Reusable "Post" action — validates the balance and locks the entry.
     */
    public static function postAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('post')
            ->label(__('resources.journal_entries.actions.post'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('resources.journal_entries.actions.post'))
            ->modalDescription(__('resources.journal_entries.actions.post_confirm'))
            ->visible(fn (JournalEntry $record) => auth()->user()?->can('post', $record))
            ->action(function (JournalEntry $record) {
                try {
                    app(JournalEntryService::class)->post($record);
                    Notification::make()
                        ->title(__('resources.journal_entries.notifications.posted'))
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('resources.common.action_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJournalEntries::route('/'),
            'create' => Pages\CreateJournalEntry::route('/create'),
            'edit' => Pages\EditJournalEntry::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['lines']);
    }
}
