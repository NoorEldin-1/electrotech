<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\AccountDirection;
use App\Enums\DocumentType;
use App\Enums\JournalStatus;
use App\Filament\Resources\JournalEntryResource\Pages;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Services\JournalEntryService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

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

    /** @var array<int, string>|null */
    protected static ?array $accountOptions = null;

    /** @var array<int, string>|null */
    protected static ?array $projectOptions = null;

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
                        // Both numbers are assigned on save, so on the create
                        // form they are two empty rows in the way of the lines.
                        Forms\Components\TextInput::make('entry_serial')
                            ->label(__('resources.journal_entries.fields.entry_serial'))
                            ->disabled()
                            ->dehydrated(false)
                            ->hiddenOn('create')
                            ->placeholder(__('resources.common.auto_generated')),

                        Forms\Components\TextInput::make('entry_number')
                            ->label(__('resources.journal_entries.fields.entry_number'))
                            ->disabled()
                            ->dehydrated(false)
                            ->hiddenOn('create')
                            ->placeholder(__('resources.common.auto_generated')),

                        // Preselected from the document-type dropdown on the
                        // list page (?document_type=…); changing it here moves
                        // the treasury account to the matching side.
                        Forms\Components\Select::make('document_type')
                            ->label(__('resources.journal_entries.fields.document_type'))
                            ->options(DocumentType::class)
                            ->default(fn (): ?string => DocumentType::tryFrom((string) request()->query('document_type'))?->value)
                            ->live()
                            ->afterStateUpdated(fn ($old, Forms\Get $get, Forms\Set $set) => static::applyTreasuryDefault(
                                $get,
                                $set,
                                previous: JournalEntryService::treasuryAccountFor($old, $get('currency')),
                            ))
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
                            ->live()
                            ->afterStateUpdated(fn ($old, Forms\Get $get, Forms\Set $set) => static::applyTreasuryDefault(
                                $get,
                                $set,
                                previous: JournalEntryService::treasuryAccountFor($get('document_type'), $old),
                            ))
                            ->required(),

                        Forms\Components\Textarea::make('notes')
                            ->label(__('resources.journal_entries.fields.notes'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                // The debit and credit sides are written in their own columns,
                // the way the entry reads on paper: no direction to pick on
                // every line, one visual row per line, and an add button per
                // side for multi-party entries.
                Forms\Components\Section::make(__('resources.journal_entries.sections.lines'))
                    ->icon('heroicon-o-list-bullet')
                    ->schema([
                        Forms\Components\Placeholder::make('balance')
                            ->hiddenLabel()
                            ->content(fn (Forms\Get $get): View => static::renderBalanceBar($get))
                            ->columnSpanFull(),

                        // Cost centre and line notes are the exception, not the
                        // rule, so they stay folded away until they are needed.
                        Forms\Components\Toggle::make('show_line_details')
                            ->label(__('resources.journal_entries.fields.show_line_details'))
                            ->helperText(__('resources.journal_entries.helpers.show_line_details'))
                            ->inline(false)
                            ->live()
                            ->dehydrated(false)
                            ->columnSpanFull(),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                static::lineRepeater('debit_lines', AccountDirection::Debit),
                                static::lineRepeater('credit_lines', AccountDirection::Credit),
                            ])
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * One side of the entry (مدين or دائن). Each row is a single line: the
     * account and its amount, with the cost centre and notes revealed only by
     * the details toggle — or on their own if the line already carries one.
     */
    protected static function lineRepeater(string $name, AccountDirection $direction): Forms\Components\Repeater
    {
        $side = $direction === AccountDirection::Debit ? 'debit' : 'credit';

        $showDetails = fn (Forms\Get $get, $state): bool => (bool) $get('../../show_line_details') || filled($state);

        return Forms\Components\Repeater::make($name)
            ->label(__("resources.journal_entries.sections.{$side}_lines"))
            ->addActionLabel(__("resources.journal_entries.actions.add_{$side}_line"))
            ->defaultItems(1)
            ->minItems(1)
            ->reorderable(false)
            ->cloneable()
            // Tightens the per-item chrome (see `.et-journal-lines` in the
            // panel theme) so a line reads as one row, not a card.
            ->extraAttributes(['class' => 'et-journal-lines'])
            ->columns(12)
            ->schema([
                Forms\Components\Hidden::make('id'),

                Forms\Components\Select::make('account_id')
                    ->label(__('resources.journal_entries.fields.account'))
                    ->options(fn (): array => static::accountOptions())
                    ->searchable()
                    ->required()
                    ->columnSpan(7),

                Forms\Components\TextInput::make('amount')
                    ->label(__('resources.journal_entries.fields.amount'))
                    ->numeric()
                    ->minValue(0.01)
                    ->required()
                    ->live(onBlur: true)
                    // A new line opens with whatever is still missing to make
                    // the entry balance, so the ordinary two-sided entry is a
                    // single click and a single number.
                    ->default(fn (Forms\Get $get): ?string => static::remainingAmount($direction, $get))
                    ->suffix(fn (Forms\Get $get): ?string => $get('../../currency'))
                    ->extraInputAttributes(['class' => 'text-start', 'inputmode' => 'decimal'])
                    ->suffixAction(
                        Forms\Components\Actions\Action::make('fillRemaining')
                            ->icon('heroicon-m-calculator')
                            ->tooltip(__('resources.journal_entries.actions.fill_remaining'))
                            ->action(function (Forms\Get $get, Forms\Set $set) use ($direction): void {
                                $set('amount', static::remainingAmount($direction, $get, ignoreCurrentLine: true));
                            }),
                    )
                    ->columnSpan(5),

                // Optional cost-center tag (الإدارة العامة): attach this line's
                // expense to an operation so the Operation Cost Center can
                // aggregate it.
                Forms\Components\Select::make('project_id')
                    ->label(__('resources.journal_entries.fields.project'))
                    ->options(fn (): array => static::projectOptions())
                    ->searchable()
                    ->nullable()
                    ->visible($showDetails)
                    ->dehydratedWhenHidden()
                    ->columnSpan(7),

                Forms\Components\TextInput::make('line_notes')
                    ->label(__('resources.journal_entries.fields.line_notes'))
                    ->maxLength(255)
                    ->visible($showDetails)
                    ->dehydratedWhenHidden()
                    ->columnSpan(5),
            ])
            ->disabled(fn (?JournalEntry $record) => $record?->isPosted() ?? false);
    }

    /**
     * What is still missing on this side for the entry to balance, or null when
     * this side is already the heavier one. `$ignoreCurrentLine` excludes the
     * line being edited so the calculator button recomputes it from scratch
     * instead of counting the value it is about to replace.
     */
    protected static function remainingAmount(AccountDirection $direction, Forms\Get $get, bool $ignoreCurrentLine = false): ?string
    {
        $debit = static::sumSide($get('../../debit_lines') ?? []);
        $credit = static::sumSide($get('../../credit_lines') ?? []);

        if ($ignoreCurrentLine) {
            $own = (float) ($get('amount') ?? 0);
            $direction === AccountDirection::Debit ? $debit -= $own : $credit -= $own;
        }

        $remaining = round($direction === AccountDirection::Debit ? $credit - $debit : $debit - $credit, 2);

        return $remaining > 0 ? (string) $remaining : null;
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $lines
     */
    protected static function sumSide(array $lines): float
    {
        return array_sum(array_map(fn (array $line): float => (float) ($line['amount'] ?? 0), $lines));
    }

    /**
     * Live debit/credit totals + balance state, shown above the lines so the
     * accountant reads it while typing instead of scrolling to the bottom.
     */
    protected static function renderBalanceBar(Forms\Get $get): View
    {
        $debit = static::sumSide($get('debit_lines') ?? []);
        $credit = static::sumSide($get('credit_lines') ?? []);
        $difference = round($debit - $credit, 2);

        return view('filament.forms.components.journal-balance-bar', [
            'debit' => $debit,
            'credit' => $credit,
            'difference' => $difference,
            'balanced' => abs($difference) < 0.001 && ($debit > 0 || $credit > 0),
        ]);
    }

    /**
     * Account and operation option lists, resolved once per request: both
     * repeaters render one select per line, and each would otherwise query.
     *
     * @return array<int, string>
     */
    protected static function accountOptions(): array
    {
        return static::$accountOptions ??= Account::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Account $account): array => [$account->getKey() => $account->display_name])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function projectOptions(): array
    {
        return static::$projectOptions ??= Project::query()
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn (Project $project): array => [
                $project->getKey() => $project->code ? "{$project->code} — {$project->name}" : (string) $project->name,
            ])
            ->all();
    }

    /**
     * Put the treasury on the side the document type implies — أمر صرف pays
     * out of it (credit), إيصال توريد pays into it (debit) — filling only an
     * empty line, so an account the user picked is never overwritten. When the
     * type or currency changes, `$previous` is the treasury line the old value
     * had filled in; it is cleared first so the entry is not left with the
     * treasury sitting on both sides.
     *
     * @param  array{direction: AccountDirection, side: string, account_id: int}|null  $previous
     */
    public static function applyTreasuryDefault(Forms\Get $get, Forms\Set $set, ?array $previous = null): void
    {
        $changes = static::treasuryStateChanges([
            'document_type' => $get('document_type'),
            'currency' => $get('currency'),
            'debit_lines' => $get('debit_lines') ?? [],
            'credit_lines' => $get('credit_lines') ?? [],
        ], $previous);

        foreach ($changes as $path => $value) {
            $set($path, $value);
        }
    }

    /**
     * The state changes the treasury default implies, as a map of state path to
     * value. Kept free of Get/Set so the create page can apply the same rule to
     * its form data on first load, before any field has been touched.
     *
     * @param  array<string, mixed>  $state
     * @param  array{direction: AccountDirection, side: string, account_id: int}|null  $previous
     * @return array<string, int|null>
     */
    public static function treasuryStateChanges(array $state, ?array $previous = null): array
    {
        $treasury = JournalEntryService::treasuryAccountFor($state['document_type'] ?? null, $state['currency'] ?? null);
        $changes = [];

        if ($previous !== null && $previous != $treasury) {
            foreach ($state[$previous['side']] ?? [] as $key => $line) {
                if (($line['account_id'] ?? null) == $previous['account_id']) {
                    $changes["{$previous['side']}.{$key}.account_id"] = null;

                    break;
                }
            }
        }

        if ($treasury === null) {
            return $changes;
        }

        foreach ($state[$treasury['side']] ?? [] as $key => $line) {
            $path = "{$treasury['side']}.{$key}.account_id";

            // A line the previous type had filled in counts as empty again.
            $accountId = array_key_exists($path, $changes) ? null : ($line['account_id'] ?? null);

            if (blank($accountId)) {
                $changes[$path] = $treasury['account_id'];

                break;
            }
        }

        return $changes;
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
                    // The form edits the two sides separately, so the view
                    // modal has to be handed the same split.
                    Tables\Actions\ViewAction::make()
                        ->mutateRecordDataUsing(fn (array $data, JournalEntry $record): array => [
                            ...$data,
                            ...JournalEntryService::splitLines($record),
                        ]),
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
