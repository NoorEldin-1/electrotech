<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\VoucherStatus;
use App\Exceptions\ExcessIssueException;
use App\Filament\Resources\IssueVoucherResource\Pages;
use App\Models\IssueVoucher;
use App\Services\IssueVoucherService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class IssueVoucherResource extends Resource
{
    protected static ?string $model = IssueVoucher::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-on-square-stack';

    protected static ?int $navigationSort = 42;

    protected static ?string $recordTitleAttribute = 'voucher_number';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.warehouse');
    }

    public static function getLabel(): string
    {
        return __('resources.issue_vouchers.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.issue_vouchers.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.issue_vouchers.navigation_label');
    }

    public static function canViewPricing(): bool
    {
        return (bool) auth()->user()?->can('inventory.view_pricing');
    }

    /**
     * What the picked work order still needs, per item, memoized for the life
     * of the request.
     *
     * The form asks for this map from several places — the auto-fill, every
     * line's "remaining" hint, the live over-issue warning — and they all run
     * inside the same Livewire round trip. Computing it once means re-picking
     * the work order five times costs five recomputations, not fifty.
     *
     * The memo is fingerprinted against the current request so it can never
     * survive into the next one (long-lived workers included).
     *
     * @return array<int, array{item_id:int, item_name:string, required:float, previously_issued:float, remaining:float, unit_cost:float}>
     */
    public static function requirementMap(
        int|string|null $workOrderId,
        int|string|null $excludeVoucherId = null,
        bool $includeDrafts = false,
    ): array {
        static $memo = [];
        static $token = null;

        $currentToken = spl_object_hash(request());

        if ($token !== $currentToken) {
            $memo = [];
            $token = $currentToken;
        }

        if (blank($workOrderId)) {
            return [];
        }

        $key = $workOrderId . ':' . ($excludeVoucherId ?? '') . ':' . (int) $includeDrafts;

        if (array_key_exists($key, $memo)) {
            return $memo[$key];
        }

        $workOrder = \App\Models\WorkOrder::find($workOrderId);

        if (! $workOrder) {
            return $memo[$key] = [];
        }

        return $memo[$key] = app(IssueVoucherService::class)
            ->requirementFor(
                $workOrder,
                $excludeVoucherId ? IssueVoucher::find($excludeVoucherId) : null,
                includeDrafts: $includeDrafts,
            )
            ->all();
    }

    /**
     * The voucher the form is editing, read off the page component.
     *
     * Filament injects `$record` relative to the component asking for it, so a
     * field INSIDE the lines repeater is handed an IssueVoucherLine, not the
     * voucher. Anything nested that needs the voucher must go through the page.
     * Null on create (and on any page that has no record yet).
     */
    protected static function currentVoucher($livewire): ?IssueVoucher
    {
        if (! is_object($livewire) || ! method_exists($livewire, 'getRecord')) {
            return null;
        }

        try {
            $record = $livewire->getRecord();
        } catch (\Throwable) {
            // CreateRecord has no record until it is saved.
            return null;
        }

        return $record instanceof IssueVoucher ? $record : null;
    }

    /**
     * The over-issue rows implied by what is on screen RIGHT NOW (unsaved
     * edits included) — the live counterpart of
     * IssueVoucherService::excessReport(), and deliberately on the same basis
     * as it: only posted movements count as already issued, so the warning the
     * user sees while typing is the same verdict the posting gate will give.
     *
     * @return array<int, array{item_name:string, excess:float}>
     */
    protected static function livingExcessRows(Forms\Get $get, ?IssueVoucher $record = null): array
    {
        $workOrderId = $get('work_order_id');
        $lines = $get('lines');

        if (blank($workOrderId) || blank($lines)) {
            return [];
        }

        $requirement = static::requirementMap($workOrderId, $record?->getKey());

        // No material plan on the order = nothing to be over.
        if ($requirement === []) {
            return [];
        }

        return collect($lines)
            ->filter(fn ($line) => filled($line['item_id'] ?? null))
            ->groupBy(fn ($line) => $line['item_id'])
            ->map(fn ($group) => (float) collect($group)->sum(fn ($line) => (float) ($line['quantity'] ?? 0)))
            ->map(function (float $quantity, $itemId) use ($requirement) {
                $row = $requirement[$itemId] ?? null;

                return [
                    'item_name' => $row['item_name'] ?? (\App\Models\Item::find($itemId)?->name ?? '—'),
                    'excess' => round($quantity - (float) ($row['remaining'] ?? 0), 4),
                ];
            })
            ->filter(fn (array $row) => $row['excess'] > 0)
            ->values()
            ->all();
    }

    /**
     * Replace the voucher's lines with what the chosen work order still needs.
     * Runs on every change of the work-order select, so switching between
     * orders always lands on a correct, fully-costed set of lines — which the
     * user is then free to edit.
     */
    protected static function fillLinesFromWorkOrder(Forms\Get $get, Forms\Set $set, ?IssueVoucher $record): void
    {
        $workOrderId = $get('work_order_id');

        if (blank($workOrderId)) {
            $set('lines', []);

            return;
        }

        // includeDrafts: a sibling draft voucher already claims part of the
        // requirement, so suggesting it again would double-issue by habit.
        $lines = collect(static::requirementMap($workOrderId, $record?->getKey(), includeDrafts: true))
            ->filter(fn (array $row) => $row['remaining'] > 0)
            ->map(fn (array $row) => [
                'item_id' => $row['item_id'],
                'quantity' => $row['remaining'],
                'unit_cost' => $row['unit_cost'],
            ])
            ->values()
            ->all();

        $set('lines', $lines);

        if ($lines === []) {
            Notification::make()
                ->warning()
                ->title(__('resources.issue_vouchers.notifications.nothing_remaining'))
                ->body(__('resources.issue_vouchers.notifications.nothing_remaining_body'))
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('resources.issue_vouchers.notifications.lines_filled'))
            ->body(__('resources.issue_vouchers.notifications.lines_filled_body', ['count' => count($lines)]))
            ->send();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('resources.issue_vouchers.sections.details'))
                    ->icon('heroicon-o-arrow-up-on-square-stack')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('voucher_number')
                            ->label(__('resources.issue_vouchers.fields.voucher_number'))
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder(__('resources.common.auto_generated')),

                        // اختيار أمر التصنيع يملأ الأصناف المنصرفة تلقائياً
                        // بالكميات المتبقية وأسعارها — وتبقى قابلة للتعديل.
                        Forms\Components\Select::make('work_order_id')
                            ->label(__('resources.issue_vouchers.fields.work_order'))
                            ->relationship('workOrder', 'wo_number')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->disabled(fn (?IssueVoucher $record) => $record?->isPosted() ?? false)
                            ->helperText(__('resources.issue_vouchers.fields.work_order_helper'))
                            ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set, ?IssueVoucher $record) => static::fillLinesFromWorkOrder($get, $set, $record)),

                        Forms\Components\DatePicker::make('voucher_date')
                            ->label(__('resources.issue_vouchers.fields.voucher_date'))
                            ->default(now())
                            ->required(),

                        Forms\Components\Textarea::make('notes')
                            ->label(__('resources.issue_vouchers.fields.notes'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make(__('resources.issue_vouchers.sections.lines'))
                    ->icon('heroicon-o-list-bullet')
                    ->description(__('resources.issue_vouchers.sections.lines_description'))
                    ->schema([
                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('refill_from_work_order')
                                ->label(__('resources.issue_vouchers.actions.refill'))
                                ->icon('heroicon-o-arrow-path')
                                ->color('gray')
                                ->requiresConfirmation()
                                ->modalDescription(__('resources.issue_vouchers.actions.refill_confirm'))
                                ->visible(fn (Forms\Get $get, ?IssueVoucher $record) => filled($get('work_order_id'))
                                    && ! ($record?->isPosted() ?? false))
                                ->action(fn (Forms\Get $get, Forms\Set $set, ?IssueVoucher $record) => static::fillLinesFromWorkOrder($get, $set, $record)),
                        ]),

                        // تحذير حي: أرخص وقت لاكتشاف كمية زائدة هو أثناء كتابتها،
                        // لا عند الوقوف أمام زر الترحيل.
                        Forms\Components\Placeholder::make('excess_warning')
                            ->hiddenLabel()
                            ->visible(fn (Forms\Get $get, ?IssueVoucher $record) => static::livingExcessRows($get, $record) !== [])
                            ->content(fn (Forms\Get $get, ?IssueVoucher $record) => new \Illuminate\Support\HtmlString(
                                '<span class="text-sm font-medium text-danger-600 dark:text-danger-400">'
                                . e(__('resources.issue_vouchers.excess.live_warning', [
                                    'items' => collect(static::livingExcessRows($get, $record))
                                        ->map(fn (array $row) => $row['item_name'] . ' (+' . rtrim(rtrim(number_format($row['excess'], 2), '0'), '.') . ')')
                                        ->implode('، '),
                                ]))
                                . '</span>'
                            ))
                            ->columnSpanFull(),

                        Forms\Components\Repeater::make('lines')
                            ->label(__('resources.issue_vouchers.fields.lines'))
                            ->relationship()
                            ->columns(3)
                            ->defaultItems(1)
                            ->schema([
                                Forms\Components\Select::make('item_id')
                                    ->label(__('resources.issue_vouchers.fields.item'))
                                    ->relationship('item', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    // ->live() so the quick-view suffix action
                                    // re-renders (becomes visible) once an item
                                    // is picked, and so the unit cost can be
                                    // defaulted from the item card below.
                                    ->live()
                                    ->afterStateUpdated(function ($state, Forms\Set $set): void {
                                        // تكلفة الوحدة تُكتب تلقائياً من كرت الصنف
                                        // بناءً على آخر تسعير له (سلايد 8).
                                        if ($state && static::canViewPricing()) {
                                            $set('unit_cost', (float) (\App\Models\Item::find($state)?->unit_cost ?? 0));
                                        }
                                    })
                                    ->suffixAction(ItemResource::quickViewAction())
                                    ->columnSpan(static::canViewPricing() ? 1 : 2),

                                Forms\Components\TextInput::make('quantity')
                                    ->label(__('resources.issue_vouchers.fields.quantity'))
                                    ->numeric()
                                    ->minValue(0.0001)
                                    ->required()
                                    // Debounced rather than instant: the warning
                                    // above is worth a round trip, but not one
                                    // per keystroke.
                                    ->live(debounce: 700)
                                    // NOTE: `$record` inside a repeater item is the
                                    // LINE, not the voucher — the voucher has to
                                    // come from the page component instead.
                                    ->helperText(function (Forms\Get $get, $livewire): ?string {
                                        $itemId = $get('item_id');
                                        $workOrderId = $get('../../work_order_id');

                                        if (blank($itemId) || blank($workOrderId)) {
                                            return null;
                                        }

                                        $row = static::requirementMap(
                                            $workOrderId,
                                            static::currentVoucher($livewire)?->getKey(),
                                        )[$itemId] ?? null;

                                        return $row === null
                                            ? __('resources.issue_vouchers.fields.not_in_plan')
                                            : __('resources.issue_vouchers.fields.remaining_required', [
                                                'qty' => rtrim(rtrim(number_format($row['remaining'], 2), '0'), '.'),
                                            ]);
                                    }),

                                Forms\Components\TextInput::make('unit_cost')
                                    ->label(__('resources.issue_vouchers.fields.unit_cost'))
                                    ->numeric()
                                    ->prefix('EGP')
                                    ->default(0)
                                    ->visible(fn () => static::canViewPricing()),
                            ])
                            ->disabled(fn (?IssueVoucher $record) => $record?->isPosted() ?? false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('voucher_number')
                    ->label(__('resources.issue_vouchers.columns.voucher_number'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('workOrder.wo_number')
                    ->label(__('resources.issue_vouchers.columns.work_order'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('voucher_date')
                    ->label(__('resources.issue_vouchers.columns.voucher_date'))
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_value')
                    ->label(__('resources.issue_vouchers.columns.total_value'))
                    ->money('EGP')
                    ->sortable()
                    ->visible(fn () => static::canViewPricing()),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('resources.issue_vouchers.columns.status'))
                    ->badge()
                    ->sortable(),

                // صرف زائد معتمَد — a posting that knowingly went past the work
                // order's plan stays visible on the list, with its reason one
                // hover away.
                Tables\Columns\IconColumn::make('has_excess')
                    ->label(__('resources.issue_vouchers.columns.has_excess'))
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-check')
                    ->trueColor('danger')
                    ->falseColor('gray')
                    ->tooltip(fn (IssueVoucher $record) => $record->hasExcess() ? $record->excess_reason : null)
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('resources.issue_vouchers.columns.status'))
                    ->options(VoucherStatus::class),
                Tables\Filters\TernaryFilter::make('has_excess')
                    ->label(__('resources.issue_vouchers.columns.has_excess')),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    static::postAction(),
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make()
                        ->visible(fn (IssueVoucher $record) => ! $record->isPosted()),
                ])
                    ->tooltip(__('resources.common.actions')),
            ]);
    }

    public static function postAction(): Tables\Actions\Action
    {
        return static::configurePostAction(Tables\Actions\Action::make('post'));
    }

    /**
     * The same posting action for a record page header (the edit screen).
     */
    public static function headerPostAction(): \Filament\Actions\Action
    {
        return static::configurePostAction(\Filament\Actions\Action::make('post'));
    }

    /**
     * The posting gate, shared by the list row action and the edit-page header
     * action so the two can never enforce different rules.
     *
     * Over-issue handling (صرف كمية زائدة عن حاجة أمر التصنيع):
     *   - the excess is computed when the modal OPENS, not for every row of the
     *     table, so the list stays cheap;
     *   - the offending items are shown with required / already issued /
     *     remaining / on this voucher / excess;
     *   - a user without `issue_vouchers.approve_excess` gets no reason field
     *     and the service refuses the post — their way out is to go back and
     *     edit the quantities;
     *   - a user with it must write why, and that reason is stamped on the
     *     voucher together with who approved it and when.
     *
     * @template T of \Filament\Actions\Action|\Filament\Tables\Actions\Action
     *
     * @param  T  $action
     * @return T
     */
    protected static function configurePostAction($action)
    {
        return $action
            ->label(__('resources.issue_vouchers.actions.post'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('resources.issue_vouchers.actions.post'))
            ->modalDescription(__('resources.issue_vouchers.actions.post_confirm'))
            ->modalSubmitActionLabel(__('resources.issue_vouchers.actions.post'))
            ->visible(fn (IssueVoucher $record) => auth()->user()?->can('post', $record))
            ->form(function (IssueVoucher $record): array {
                $excess = app(IssueVoucherService::class)->excessReport($record);

                if ($excess === []) {
                    return [];
                }

                $canApprove = (bool) auth()->user()?->can('approveExcess', $record);

                return [
                    Forms\Components\Placeholder::make('excess_report')
                        ->hiddenLabel()
                        ->content(fn () => view('filament.issue-vouchers.excess-warning', [
                            'rows' => $excess,
                            'canApprove' => $canApprove,
                        ]))
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('excess_reason')
                        ->label(__('resources.issue_vouchers.fields.excess_reason'))
                        ->helperText(__('resources.issue_vouchers.fields.excess_reason_helper'))
                        ->rows(3)
                        ->required()
                        ->visible($canApprove)
                        ->columnSpanFull(),
                ];
            })
            ->action(function (IssueVoucher $record, array $data) {
                try {
                    app(IssueVoucherService::class)->post(
                        $record,
                        allowExcess: (bool) auth()->user()?->can('approveExcess', $record),
                        excessReason: $data['excess_reason'] ?? null,
                    );

                    Notification::make()
                        ->title(__('resources.issue_vouchers.notifications.posted'))
                        ->success()
                        ->send();
                } catch (ExcessIssueException $e) {
                    // The store keeper may not wave the excess through: name
                    // the items and send them back to the lines to fix them.
                    Notification::make()
                        ->title(__('resources.issue_vouchers.notifications.excess_blocked'))
                        ->body(collect($e->rows)
                            ->map(fn (array $row) => $row['item_name'] . ' (+' . number_format($row['excess'], 2) . ')')
                            ->implode('، '))
                        ->danger()
                        ->persistent()
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
            'index' => Pages\ListIssueVouchers::route('/'),
            'create' => Pages\CreateIssueVoucher::route('/create'),
            'edit' => Pages\EditIssueVoucher::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['workOrder', 'lines'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
