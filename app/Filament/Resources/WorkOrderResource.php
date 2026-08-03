<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\WorkOrderStatus;
use App\Filament\Resources\WorkOrderResource\Pages;
use App\Models\WorkOrder;
use App\Services\DepreciationVoucherService;
use App\Services\QualitySheetService;
use App\Services\ReturnVoucherService;
use App\Services\WorkOrderMaterialVarianceService;
use App\Services\WorkOrderService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class WorkOrderResource extends Resource
{
    protected static ?string $model = WorkOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'wo_number';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.technical_office');
    }

    public static function getLabel(): string
    {
        return __('resources.work_orders.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.work_orders.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.work_orders.navigation_label');
    }

    /**
     * Whether the current user may see costs/prices. Material unit costs are
     * hidden from users without this permission (same rule as the vouchers).
     */
    public static function canViewPricing(): bool
    {
        return (bool) auth()->user()?->can('inventory.view_pricing');
    }

    /**
     * Re-derive everything that hangs off the finished-product lines whenever
     * one of them changes — the order's total planned quantity, and the
     * material table that the issue vouchers are cut from.
     *
     * Called from inside the outputs repeater, hence the `../../` paths that
     * climb out of the repeater item back to the form.
     */
    protected static function syncPlanFromOutputs(Forms\Get $get, Forms\Set $set): void
    {
        $outputs = collect($get('../../outputs') ?? []);

        $set('../../planned_quantity', round(
            (float) $outputs->sum(fn ($row) => (float) ($row['planned_quantity'] ?? 0)),
            4,
        ));

        static::refreshStandardMaterials($outputs->all(), $get('../../materials') ?? [], function ($lines) use ($set) {
            $set('../../materials', $lines);
        });
    }

    /**
     * Rebuild the material table from the products' standard recipes — but only
     * while the table is still the machine's. The moment a line has been
     * hand-edited (`is_manual`), the office owns that table and an automatic
     * refill would silently throw its work away; the user is told instead, and
     * the explicit "جلب الخامات القياسية" button remains the way to overwrite.
     *
     * @param  array<int, mixed>  $outputs   current state of the products repeater
     * @param  array<int, mixed>  $materials current state of the materials repeater
     * @param  callable(array<int, mixed>): void  $apply
     */
    protected static function refreshStandardMaterials(array $outputs, array $materials, callable $apply): void
    {
        $hasManualEdits = collect($materials)->contains(fn ($row) => (bool) ($row['is_manual'] ?? false));

        if ($hasManualEdits) {
            Notification::make()
                ->warning()
                ->title(__('resources.work_orders.notifications.materials_not_refreshed'))
                ->body(__('resources.work_orders.notifications.materials_not_refreshed_body'))
                ->send();

            return;
        }

        $result = app(\App\Services\WorkOrderMaterialService::class)->standardMaterialsForOutputs(
            collect($outputs)->map(fn ($row) => [
                'item_id' => $row['item_id'] ?? null,
                'planned_quantity' => $row['planned_quantity'] ?? 0,
            ])->all()
        );

        // Products without an approved standard recipe are named rather than
        // silently dropped — the rest of the order still gets its materials.
        if ($result['missing'] !== []) {
            Notification::make()
                ->warning()
                ->title(__('resources.work_orders.notifications.no_standard_bom_for'))
                ->body(implode('، ', $result['missing']))
                ->send();
        }

        if ($result['lines'] === [] && $materials === []) {
            return;
        }

        $apply($result['lines']);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('resources.work_orders.sections.wo_details'))
                    ->icon('heroicon-o-cog-6-tooth')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('wo_number')
                            ->label(__('resources.work_orders.fields.wo_number'))
                            ->default(fn () => WorkOrder::generateWoNumber())
                            ->disabled()
                            ->dehydrated()
                            ->required()
                            ->unique(ignoreRecord: true),

                        Forms\Components\TextInput::make('title')
                            ->label(__('resources.work_orders.fields.title'))
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Select::make('project_id')
                            ->label(__('resources.work_orders.fields.project'))
                            ->relationship('project', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),

                        Forms\Components\Select::make('status')
                            ->label(__('resources.work_orders.fields.status'))
                            ->options(WorkOrderStatus::class)
                            // سلايد 5 — the order is authored as a Draft; the PMO
                            // manager approves it (approve_order) before the floor
                            // can start manufacturing.
                            ->default(WorkOrderStatus::Draft)
                            ->required(),

                        Forms\Components\Select::make('priority')
                            ->label(__('resources.work_orders.fields.priority'))
                            ->options(fn () => [
                                'low' => __('resources.work_orders.priority_options.low'),
                                'normal' => __('resources.work_orders.priority_options.normal'),
                                'high' => __('resources.work_orders.priority_options.high'),
                                'urgent' => __('resources.work_orders.priority_options.urgent'),
                            ])
                            ->default('normal')
                            ->required()
                            ->native(false),

                        Forms\Components\Select::make('assigned_to')
                            ->label(__('resources.work_orders.fields.assigned_to'))
                            ->relationship('assignedTo', 'name')
                            ->searchable()
                            ->preload(),

                        Forms\Components\Hidden::make('created_by')
                            ->default(fn () => auth()->id()),
                    ]),

                // المنتجات التامة — an order produces MORE THAN ONE finished
                // product, each with its own planned quantity. Those quantities
                // are what scales the material table (and therefore the issue
                // vouchers), and their sum is the order's planned quantity.
                Forms\Components\Section::make(__('resources.work_orders.sections.outputs'))
                    ->icon('heroicon-o-cube')
                    ->description(__('resources.work_orders.sections.outputs_description'))
                    ->schema([
                        Forms\Components\Repeater::make('outputs')
                            ->label(__('resources.work_orders.fields.outputs'))
                            ->relationship()
                            ->columns(2)
                            ->minItems(1)
                            ->defaultItems(1)
                            ->required()
                            ->reorderable(false)
                            ->addActionLabel(__('resources.work_orders.actions.add_output'))
                            ->itemLabel(fn (array $state): ?string => filled($state['item_id'] ?? null)
                                ? \App\Models\Item::find($state['item_id'])?->name
                                : null)
                            ->schema([
                                Forms\Components\Select::make('item_id')
                                    ->label(__('resources.work_orders.fields.output_item'))
                                    ->relationship(
                                        'item',
                                        'name',
                                        fn (Builder $query) => $query->whereIn('type', ['finished_good', 'semi_finished']),
                                    )
                                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->sku} — {$record->name}")
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    // The same product twice would double-count
                                    // the plan and the recipe.
                                    ->distinct()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->live()
                                    ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => static::syncPlanFromOutputs($get, $set))
                                    ->columnSpan(1),

                                // الكمية المخططة لكل منتج — the denominator of
                                // that product's efficiency and the multiplier
                                // of its standard recipe.
                                Forms\Components\TextInput::make('planned_quantity')
                                    ->label(__('resources.work_orders.fields.output_planned_quantity'))
                                    ->numeric()
                                    ->required()
                                    ->minValue(0.0001)
                                    ->rule('gt:0')
                                    ->live(debounce: 600)
                                    ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => static::syncPlanFromOutputs($get, $set))
                                    ->columnSpan(1),

                                // Filled at the QA-submission stage only —
                                // never authored on this form.
                                Forms\Components\Hidden::make('produced_quantity')->default(0),
                                Forms\Components\Hidden::make('waste_quantity')->default(0),
                            ])
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make(__('resources.work_orders.sections.quantities_schedule'))
                    ->icon('heroicon-o-calculator')
                    ->columns(3)
                    ->schema([
                        // الكمية المخططة هي مقام كل نِسَب الأمر (الكفاءة، الفاقد،
                        // انحراف التكلفة)، وهي الآن مشتقّة: مجموع كميات المنتجات
                        // التامة. تُعاد الاشتقاق على الخادم بعد كل حفظ، ويُفرَض
                        // كونها > 0 في WorkOrderService قبل الإفراج للتصنيع.
                        Forms\Components\TextInput::make('planned_quantity')
                            ->label(__('resources.work_orders.fields.planned_quantity'))
                            ->helperText(__('resources.work_orders.fields.planned_quantity_helper'))
                            ->numeric()
                            ->disabled()
                            ->dehydrated()
                            ->default(0),

                        Forms\Components\DatePicker::make('planned_start_date')
                            ->label(__('resources.work_orders.fields.planned_start_date'))
                            ->required()
                            ->default(fn () => now()->toDateString()),
                        Forms\Components\DatePicker::make('planned_end_date')
                            ->label(__('resources.work_orders.fields.planned_end_date'))
                            ->required()
                            ->afterOrEqual('planned_start_date'),
                    ]),

                // المواصفات الفنية — سلايد 2–3: authored here by the PMO, then
                // snapshot-copied into the quality sheet's operation data
                // (بيانات العملية تُملأ تلقائياً بناءً على أمر التصنيع).
                Forms\Components\Section::make(__('resources.work_orders.sections.specs'))
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->description(__('resources.work_orders.sections.specs_description'))
                    ->columns(3)
                    ->collapsible()
                    ->schema([
                        Forms\Components\TextInput::make('conductor_type')
                            ->label(__('resources.work_orders.fields.conductor_type'))
                            ->maxLength(255),
                        Forms\Components\TextInput::make('cross_section')
                            ->label(__('resources.work_orders.fields.cross_section'))
                            ->maxLength(255),
                        Forms\Components\TextInput::make('cross_section_e')
                            ->label(__('resources.work_orders.fields.cross_section_e'))
                            ->maxLength(255),
                        Forms\Components\TextInput::make('external_body')
                            ->label(__('resources.work_orders.fields.external_body'))
                            ->maxLength(255),
                        Forms\Components\TextInput::make('protection_degree')
                            ->label(__('resources.work_orders.fields.protection_degree'))
                            ->maxLength(255),
                        Forms\Components\TextInput::make('paint')
                            ->label(__('resources.work_orders.fields.paint'))
                            ->maxLength(255),
                        Forms\Components\TextInput::make('model')
                            ->label(__('resources.work_orders.fields.model'))
                            ->maxLength(255),
                        Forms\Components\TextInput::make('ampere')
                            ->label(__('resources.work_orders.fields.ampere'))
                            ->maxLength(255),
                        Forms\Components\TextInput::make('poles_count')
                            ->label(__('resources.work_orders.fields.poles_count'))
                            ->numeric()
                            ->minValue(0),
                    ]),

                // جدول الخامات — سلايد 6: seeded from the finished good's
                // standard BOM, then freely editable for this order (المرونة
                // والتعديل اليدوي). The issue voucher is built from these lines.
                Forms\Components\Section::make(__('resources.work_orders.sections.materials'))
                    ->icon('heroicon-o-list-bullet')
                    ->description(__('resources.work_orders.sections.materials_description'))
                    ->schema([
                        Forms\Components\Actions::make([
                            // Explicit, confirmed overwrite: merges the standard
                            // recipes of ALL the order's finished products,
                            // each scaled by its own planned quantity, and
                            // replaces the table — manual edits included.
                            Forms\Components\Actions\Action::make('fetch_standard_materials')
                                ->label(__('resources.work_orders.actions.fetch_standard_materials'))
                                ->icon('heroicon-o-arrow-down-tray')
                                ->color('gray')
                                ->requiresConfirmation()
                                ->modalDescription(__('resources.work_orders.actions.fetch_standard_materials_confirm'))
                                ->visible(fn (Forms\Get $get) => filled($get('outputs')))
                                ->action(function (Forms\Get $get, Forms\Set $set): void {
                                    $result = app(\App\Services\WorkOrderMaterialService::class)
                                        ->standardMaterialsForOutputs(
                                            collect($get('outputs') ?? [])->map(fn ($row) => [
                                                'item_id' => $row['item_id'] ?? null,
                                                'planned_quantity' => $row['planned_quantity'] ?? 0,
                                            ])->all()
                                        );

                                    if ($result['missing'] !== []) {
                                        Notification::make()->warning()
                                            ->title(__('resources.work_orders.notifications.no_standard_bom_for'))
                                            ->body(implode('، ', $result['missing']))
                                            ->send();
                                    }

                                    if ($result['lines'] === []) {
                                        return;
                                    }

                                    $set('materials', $result['lines']);

                                    Notification::make()->success()
                                        ->title(__('resources.work_orders.notifications.materials_fetched'))
                                        ->send();
                                }),
                        ]),

                        Forms\Components\Repeater::make('materials')
                            ->label(__('resources.work_orders.fields.materials'))
                            ->relationship()
                            ->columns(static::canViewPricing() ? 3 : 2)
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->schema([
                                Forms\Components\Select::make('item_id')
                                    ->label(__('resources.work_orders.fields.material_item'))
                                    ->relationship(
                                        'item',
                                        'name',
                                        fn (Builder $query) => $query->where('is_scrap', false)
                                            ->whereIn('type', ['raw_material', 'consumable', 'semi_finished']),
                                    )
                                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->sku} — {$record->name}")
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Forms\Set $set): void {
                                        // Default the unit cost from the item's
                                        // card (سلايد 8) when a row is added.
                                        if ($state && static::canViewPricing()) {
                                            $set('unit_cost', (float) (\App\Models\Item::find($state)?->unit_cost ?? 0));
                                        }
                                    })
                                    ->suffixAction(ItemResource::quickViewAction())
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('quantity')
                                    ->label(__('resources.work_orders.fields.material_quantity'))
                                    ->numeric()
                                    ->minValue(0.0001)
                                    ->required()
                                    // Flag hand-edited rows so reports can tell a
                                    // manual override from the standard recipe.
                                    ->live(debounce: 500)
                                    ->afterStateUpdated(fn (Forms\Set $set) => $set('is_manual', true))
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('unit_cost')
                                    ->label(__('resources.work_orders.fields.material_unit_cost'))
                                    ->numeric()
                                    ->prefix('EGP')
                                    ->default(0)
                                    ->visible(fn () => static::canViewPricing())
                                    ->columnSpan(1),

                                Forms\Components\Hidden::make('is_manual')
                                    ->default(false),
                            ])
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make(__('resources.work_orders.sections.qa_gate'))
                    ->icon('heroicon-o-shield-check')
                    ->description(__('resources.work_orders.sections.qa_gate_description'))
                    ->columns(2)
                    ->schema([
                        Forms\Components\Placeholder::make('order_approval_status')
                            ->label(__('resources.work_orders.fields.order_approved_at'))
                            ->content(fn (?WorkOrder $record) => $record?->isOrderApproved()
                                ? __('resources.work_orders.order_approval.approved_by', [
                                    'name' => $record->orderApprovedBy?->name,
                                    'date' => $record->order_approved_at?->format('Y-m-d H:i'),
                                ])
                                : __('resources.work_orders.order_approval.pending')),

                        Forms\Components\Placeholder::make('qa_status')
                            ->label(__('resources.work_orders.fields.qa_status'))
                            ->content(fn (?WorkOrder $record) => $record?->isQaApproved()
                                ? __('resources.work_orders.qa.approved_by', [
                                    'name' => $record->qaApprovedBy?->name,
                                    'date' => $record->qa_approved_at?->format('Y-m-d H:i'),
                                ])
                                : __('resources.work_orders.qa.pending')),

                        Forms\Components\Placeholder::make('manufacturing_finish_status')
                            ->label(__('resources.work_orders.fields.manufacturing_finished_at'))
                            ->content(fn (?WorkOrder $record) => $record?->isManufacturingFinished()
                                ? __('resources.work_orders.manufacturing.finished_at', [
                                    'date' => $record->manufacturing_finished_at?->format('Y-m-d H:i'),
                                    'duration' => $record->manufacturing_duration_human ?? '—',
                                    'name' => $record->manufacturingFinishedBy?->name ?? '—',
                                ])
                                : __('resources.work_orders.manufacturing.not_finished')),

                        Forms\Components\Textarea::make('qa_notes')
                            ->label(__('resources.work_orders.fields.qa_notes'))
                            ->rows(3)
                            ->disabled()
                            ->dehydrated()
                            ->columnSpanFull(),

                        // The finish gate stated on the record itself, so the
                        // office can see WHY the button is not there yet.
                        Forms\Components\Placeholder::make('finish_gate')
                            ->label(__('resources.work_orders.fields.finish_gate'))
                            ->visible(fn (?WorkOrder $record) => $record !== null && ! $record->isManufacturingFinished())
                            ->content(fn (?WorkOrder $record) => $record?->isOrderApproved() && $record?->isQaApproved()
                                ? __('resources.work_orders.manufacturing.finish_allowed')
                                : __('resources.work_orders.manufacturing.finish_blocked'))
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make(__('resources.work_orders.sections.description'))
                    ->schema([
                        Forms\Components\Textarea::make('description')
                            ->label(__('resources.work_orders.fields.description'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('wo_number')
                    ->label(__('resources.work_orders.columns.wo_number'))
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('title')
                    ->label(__('resources.work_orders.columns.title'))
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('project.name')
                    ->label(__('resources.work_orders.columns.project'))
                    ->searchable()
                    ->limit(25),

                // المنتجات التامة — an order carries several now, so the list
                // shows them all rather than a single product name.
                Tables\Columns\TextColumn::make('outputs.item.name')
                    ->label(__('resources.work_orders.columns.outputs'))
                    ->badge()
                    ->color('gray')
                    ->limitList(2)
                    ->expandableLimitedList()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('resources.work_orders.columns.status'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('priority')
                    ->label(__('resources.work_orders.columns.priority'))
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'urgent' => 'danger',
                        'high' => 'warning',
                        'normal' => 'info',
                        'low' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => __("resources.work_orders.priority_options.{$state}")),

                Tables\Columns\TextColumn::make('planned_quantity')
                    ->label(__('resources.work_orders.columns.planned'))
                    ->numeric(2),

                Tables\Columns\TextColumn::make('produced_quantity')
                    ->label(__('resources.work_orders.columns.produced'))
                    ->numeric(2)
                    ->color('success'),

                Tables\Columns\TextColumn::make('estimated_cost')
                    ->label(__('resources.work_orders.columns.estimated_cost'))
                    ->numeric(2)
                    ->visible(fn () => auth()->user()?->can('operations.view_cost') ?? false)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('actual_material_cost')
                    ->label(__('resources.work_orders.columns.actual_cost'))
                    ->numeric(2)
                    ->visible(fn () => auth()->user()?->can('operations.view_cost') ?? false)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('cost_variance')
                    ->label(__('resources.work_orders.columns.cost_variance'))
                    ->state(fn (WorkOrder $record): float => $record->cost_variance)
                    ->numeric(2)
                    ->color(fn (WorkOrder $record): string => $record->cost_variance > 0 ? 'danger' : 'success')
                    ->visible(fn () => auth()->user()?->can('operations.view_cost') ?? false)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('assignedTo.name')
                    ->label(__('resources.work_orders.columns.assigned_to'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('planned_start_date')
                    ->label(__('resources.work_orders.columns.start_date'))
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('manufacturing_finished_at')
                    ->label(__('resources.work_orders.columns.finished_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('manufacturing_duration_human')
                    ->label(__('resources.work_orders.columns.duration'))
                    ->state(fn (WorkOrder $record): ?string => $record->manufacturing_duration_human)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('resources.work_orders.columns.status'))
                    ->options(WorkOrderStatus::class)
                    ->multiple(),
                Tables\Filters\SelectFilter::make('priority')
                    ->label(__('resources.work_orders.columns.priority'))
                    ->options(fn () => [
                        'low' => __('resources.work_orders.priority_options.low'),
                        'normal' => __('resources.work_orders.priority_options.normal'),
                        'high' => __('resources.work_orders.priority_options.high'),
                        'urgent' => __('resources.work_orders.priority_options.urgent'),
                    ]),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),

                    // Approve order (اعتماد مدير مكتب المشروعات — سلايد 5): the PMO
                    // manager releases the draft to the floor (Draft → Pending).
                    // Manufacturing cannot start before this gate. Idempotent on
                    // retry, like the QA actions.
                    Tables\Actions\Action::make('approve_order')
                        ->label(__('resources.work_orders.actions.approve_order'))
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (WorkOrder $record) => $record->status === WorkOrderStatus::Draft
                            && auth()->user()?->can('work_orders.approve_order'))
                        ->action(function (WorkOrder $record) {
                            $fresh = $record->fresh();
                            if ($fresh && $fresh->status !== WorkOrderStatus::Draft) {
                                Notification::make()->success()->title(__('resources.work_orders.notifications.order_approved'))->send();
                                return;
                            }
                            try {
                                app(WorkOrderService::class)->approveOrder($record);
                                Notification::make()->success()->title(__('resources.work_orders.notifications.order_approved'))->send();
                            } catch (\RuntimeException $e) {
                                Notification::make()->danger()->title(__('resources.work_orders.notifications.failed'))->body($e->getMessage())->send();
                            }
                        }),

                    // Start WO action
                    //
                    // The action is idempotent under a flaky connection: if a
                    // second submission arrives because the user's browser
                    // didn't see the first response and retried, we re-read
                    // the record from the DB and silently succeed if the WO
                    // has already advanced past the target state. Combined
                    // with HTTP-level Idempotency-Key middleware, this means
                    // a double-click on a 2 s RTT link can never start the
                    // WO twice or surface a confusing "wrong state" toast.
                    Tables\Actions\Action::make('start')
                        ->label(__('resources.work_orders.actions.start'))
                        ->icon('heroicon-o-play')
                        ->color('info')
                        ->requiresConfirmation()
                        ->visible(fn (WorkOrder $record) => $record->status === WorkOrderStatus::Pending
                            && auth()->user()?->can('work_orders.start'))
                        ->action(function (WorkOrder $record) {
                            $fresh = $record->fresh();
                            if ($fresh && $fresh->status !== WorkOrderStatus::Pending) {
                                Notification::make()->success()->title(__('resources.work_orders.notifications.started'))->send();
                                return;
                            }
                            try {
                                app(WorkOrderService::class)->start($record);
                                Notification::make()->success()->title(__('resources.work_orders.notifications.started'))->send();
                            } catch (\RuntimeException $e) {
                                Notification::make()->danger()->title(__('resources.work_orders.notifications.failed'))->body($e->getMessage())->send();
                            }
                        }),

                    // Issue materials — creates a DRAFT issue voucher (إذن صرف)
                    // from the BOM. The warehouse then posts it, moving the raw
                    // materials into work-in-progress.
                    Tables\Actions\Action::make('issue_materials')
                        ->label(__('resources.work_orders.actions.issue_materials'))
                        ->icon('heroicon-o-arrow-up-on-square-stack')
                        ->color('warning')
                        ->requiresConfirmation()
                        // Materials can be issued from the order's own material
                        // table (the normal case now that the linked-BOM field
                        // is gone) or, for legacy orders, from the BOM.
                        ->visible(fn (WorkOrder $record) => ($record->bom_id !== null || $record->materials()->exists())
                            && in_array($record->status, [WorkOrderStatus::Pending, WorkOrderStatus::InProgress], true)
                            && auth()->user()?->can('issue_vouchers.create'))
                        ->action(function (WorkOrder $record) {
                            try {
                                $voucher = app(WorkOrderService::class)->issueMaterials($record);
                                Notification::make()
                                    ->success()
                                    ->title(__('resources.work_orders.notifications.issue_voucher_created'))
                                    ->body($voucher->voucher_number)
                                    ->send();
                            } catch (\RuntimeException $e) {
                                Notification::make()->danger()->title(__('resources.work_orders.notifications.failed'))->body($e->getMessage())->send();
                            }
                        }),

                    // Return scrap — creates a DRAFT return voucher (إذن ارتداد)
                    // pre-filled with the issued materials. The warehouse sets the
                    // scrap quantities and posts it, sending the scrap back to raw
                    // stock under a different code and reversing its cost.
                    Tables\Actions\Action::make('return_scrap')
                        ->label(__('resources.work_orders.actions.return_scrap'))
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->visible(fn (WorkOrder $record) => $record->status === WorkOrderStatus::InProgress
                            && auth()->user()?->can('return_vouchers.create'))
                        ->action(function (WorkOrder $record) {
                            try {
                                $voucher = app(ReturnVoucherService::class)->createFromWorkOrder($record);
                                Notification::make()
                                    ->success()
                                    ->title(__('resources.work_orders.notifications.return_voucher_created'))
                                    ->body($voucher->voucher_number)
                                    ->send();
                            } catch (\RuntimeException $e) {
                                Notification::make()->danger()->title(__('resources.work_orders.notifications.failed'))->body($e->getMessage())->send();
                            }
                        }),

                    // Write off loss — creates a DRAFT depreciation voucher (إذن
                    // إهلاك) pre-filled with the issued materials. The user picks the
                    // loss type and quantities and posts it, taking the loss out of
                    // WIP and carrying its value to the loss account.
                    Tables\Actions\Action::make('write_off_loss')
                        ->label(__('resources.work_orders.actions.write_off_loss'))
                        ->icon('heroicon-o-fire')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn (WorkOrder $record) => $record->status === WorkOrderStatus::InProgress
                            && auth()->user()?->can('depreciation_vouchers.create'))
                        ->action(function (WorkOrder $record) {
                            try {
                                $voucher = app(DepreciationVoucherService::class)->createFromWorkOrder($record);
                                Notification::make()
                                    ->success()
                                    ->title(__('resources.work_orders.notifications.depreciation_voucher_created'))
                                    ->body($voucher->voucher_number)
                                    ->send();
                            } catch (\RuntimeException $e) {
                                Notification::make()->danger()->title(__('resources.work_orders.notifications.failed'))->body($e->getMessage())->send();
                            }
                        }),

                    // Submit for QA — same idempotency story as `start`.
                    Tables\Actions\Action::make('submit_qa')
                        ->label(__('resources.work_orders.actions.submit_qa'))
                        ->icon('heroicon-o-shield-check')
                        ->color('warning')
                        ->visible(fn (WorkOrder $record) => $record->status === WorkOrderStatus::InProgress
                            && auth()->user()?->can('work_orders.submit_qa'))
                        // هنا — وهنا فقط — تُدخَل الكمية المنتجة وكمية الهالك:
                        // مرحلة من مراحل الأمر، لا حقل في شاشة الإنشاء/التعديل.
                        // A multi-product order reports product by product.
                        ->modalDescription(__('resources.work_orders.actions.submit_qa_description'))
                        ->fillForm(fn (WorkOrder $record) => [
                            'results' => $record->outputs()->with('item')->get()
                                ->map(fn ($output) => [
                                    'output_id' => $output->id,
                                    'product' => $output->item?->name ?? '—',
                                    'planned_quantity' => (float) $output->planned_quantity,
                                    'produced_quantity' => (float) $output->produced_quantity,
                                    'waste_quantity' => (float) $output->waste_quantity,
                                ])->all(),
                        ])
                        ->form(fn (WorkOrder $record) => [
                            Forms\Components\Repeater::make('results')
                                ->label(__('resources.work_orders.fields.outputs'))
                                ->columns(3)
                                ->addable(false)
                                ->deletable(false)
                                ->reorderable(false)
                                ->itemLabel(fn (array $state): ?string => $state['product'] ?? null)
                                ->schema([
                                    Forms\Components\Hidden::make('output_id'),
                                    Forms\Components\Hidden::make('product'),
                                    Forms\Components\TextInput::make('planned_quantity')
                                        ->label(__('resources.work_orders.fields.planned_quantity'))
                                        ->numeric()
                                        ->disabled(),
                                    Forms\Components\TextInput::make('produced_quantity')
                                        ->label(__('resources.work_orders.fields.produced_quantity'))
                                        ->numeric()
                                        ->minValue(0)
                                        ->required(),
                                    Forms\Components\TextInput::make('waste_quantity')
                                        ->label(__('resources.work_orders.fields.waste_quantity'))
                                        ->numeric()
                                        ->minValue(0)
                                        ->required()
                                        ->default(0),
                                ])
                                // Legacy orders with no product lines still
                                // report a single order-level pair.
                                ->visible(fn () => $record->outputs()->exists())
                                ->columnSpanFull(),

                            Forms\Components\TextInput::make('produced_quantity')
                                ->label(__('resources.work_orders.fields.produced_quantity'))
                                ->numeric()
                                ->required()
                                ->visible(fn () => ! $record->outputs()->exists()),
                            Forms\Components\TextInput::make('waste_quantity')
                                ->label(__('resources.work_orders.fields.waste_quantity'))
                                ->numeric()
                                ->required()
                                ->default(0)
                                ->visible(fn () => ! $record->outputs()->exists()),
                        ])
                        ->action(function (WorkOrder $record, array $data) {
                            $fresh = $record->fresh();
                            if ($fresh && $fresh->status !== WorkOrderStatus::InProgress) {
                                Notification::make()->success()->title(__('resources.work_orders.notifications.submitted_qa'))->send();
                                return;
                            }

                            $results = $data['results'] ?? [[
                                'output_id' => null,
                                'produced_quantity' => $data['produced_quantity'] ?? 0,
                                'waste_quantity' => $data['waste_quantity'] ?? 0,
                            ]];

                            try {
                                app(WorkOrderService::class)->submitForQa($record, array_values($results));
                                Notification::make()->success()->title(__('resources.work_orders.notifications.submitted_qa'))->send();
                            } catch (\RuntimeException $e) {
                                Notification::make()->danger()->title(__('resources.work_orders.notifications.failed'))->body($e->getMessage())->send();
                            }
                        }),

                    // Approve QA — idempotent: a retry that lands after the
                    // first approval finds qa_approved_by already set and
                    // returns success without touching the row again.
                    Tables\Actions\Action::make('approve_qa')
                        ->label(__('resources.work_orders.actions.approve_qa'))
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->visible(fn (WorkOrder $record) => $record->status === WorkOrderStatus::QaReview
                            && ! $record->isQaApproved()
                            && auth()->user()?->can('work_orders.approve_qa'))
                        ->form([
                            Forms\Components\Textarea::make('qa_notes')
                                ->label(__('resources.work_orders.fields.qa_notes'))
                                ->rows(3),
                        ])
                        ->action(function (WorkOrder $record, array $data) {
                            $fresh = $record->fresh();
                            if ($fresh && $fresh->isQaApproved()) {
                                Notification::make()->success()->title(__('resources.work_orders.notifications.qa_approved'))->send();
                                return;
                            }
                            try {
                                app(WorkOrderService::class)->approveQa($record, $data['qa_notes'] ?? null);
                                Notification::make()->success()->title(__('resources.work_orders.notifications.qa_approved'))->send();
                            } catch (\RuntimeException $e) {
                                Notification::make()->danger()->title(__('resources.work_orders.notifications.failed'))->body($e->getMessage())->send();
                            }
                        }),

                    // Complete WO — idempotent on retry.
                    Tables\Actions\Action::make('complete')
                        ->label(__('resources.work_orders.actions.complete'))
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (WorkOrder $record) => $record->status === WorkOrderStatus::QaReview
                            && $record->isQaApproved()
                            && auth()->user()?->can('work_orders.complete'))
                        ->action(function (WorkOrder $record) {
                            $fresh = $record->fresh();
                            if ($fresh && $fresh->status === WorkOrderStatus::Completed) {
                                Notification::make()->success()->title(__('resources.work_orders.notifications.completed'))->send();
                                return;
                            }
                            try {
                                app(WorkOrderService::class)->complete($record);
                                Notification::make()->success()->title(__('resources.work_orders.notifications.completed'))->send();
                            } catch (\RuntimeException $e) {
                                Notification::make()->danger()->title(__('resources.work_orders.notifications.failed'))->body($e->getMessage())->send();
                            }
                        }),

                    // Finish Manufacturing (انتهاء التصنيع) — records the
                    // manufacturing time and tells every department the product
                    // is ready for delivery (التصنيع سلايد 2). Never touches
                    // stock or cost; that stays with complete().
                    //
                    // STRICTEST GATE ON THE ORDER: both the PMO approval and the
                    // QA sign-off must already be on the record. The rule lives
                    // in WorkOrderService::canFinishManufacturing() so the button
                    // and the service can never drift apart.
                    // Idempotent on retry (re-reads fresh and succeeds silently).
                    Tables\Actions\Action::make('finish_manufacturing')
                        ->label(__('resources.work_orders.actions.finish_manufacturing'))
                        ->icon('heroicon-o-flag')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalDescription(__('resources.work_orders.actions.finish_manufacturing_confirm'))
                        ->visible(fn (WorkOrder $record) => app(WorkOrderService::class)->canFinishManufacturing($record)
                            && auth()->user()?->can('work_orders.finish_manufacturing'))
                        ->action(function (WorkOrder $record) {
                            $fresh = $record->fresh();
                            if ($fresh && $fresh->isManufacturingFinished()) {
                                Notification::make()->success()->title(__('resources.work_orders.notifications.manufacturing_finished'))->send();
                                return;
                            }
                            try {
                                app(WorkOrderService::class)->finishManufacturing($record);
                                Notification::make()->success()->title(__('resources.work_orders.notifications.manufacturing_finished'))->send();
                            } catch (\RuntimeException $e) {
                                Notification::make()->danger()->title(__('resources.work_orders.notifications.failed'))->body($e->getMessage())->send();
                            }
                        }),

                    // Quality Sheet (ورقة الجودة) — opens (or creates) the work
                    // order's quality sheet for the QA department to fill and the
                    // factory manager to approve (التصنيع سلايد 2 سفلي + 3).
                    // Available once manufacturing has finished.
                    Tables\Actions\Action::make('quality_sheet')
                        ->label(__('resources.work_orders.actions.quality_sheet'))
                        ->icon('heroicon-o-clipboard-document-check')
                        ->color('primary')
                        ->visible(fn (WorkOrder $record) => $record->manufacturing_finished_at !== null
                            && auth()->user()?->can('quality_sheets.create'))
                        ->action(function (WorkOrder $record) {
                            $sheet = app(QualitySheetService::class)->ensureForWorkOrder($record);

                            // An approved sheet is final and can't be edited (the edit
                            // policy blocks it → 403), so send it to the read-only view.
                            $page = $sheet->isApproved() ? 'view' : 'edit';

                            return redirect(QualitySheetResource::getUrl($page, ['record' => $sheet->getKey()]));
                        }),

                    // مقارنة الخامات — planned (BOM × quantity) against what the
                    // issue vouchers actually pulled, net of returns
                    // (قائمة المواد سلايد 1). Read-only; the difference is the
                    // material loss of the order.
                    Tables\Actions\Action::make('material_variance')
                        ->label(__('resources.work_orders.actions.material_variance'))
                        ->icon('heroicon-o-scale')
                        ->color('gray')
                        ->modalHeading(fn (WorkOrder $record) => __('resources.material_variance.heading', ['order' => $record->wo_number]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('resources.common.close'))
                        ->modalWidth('5xl')
                        ->modalContent(fn (WorkOrder $record) => view('filament.work-orders.material-variance', [
                            'workOrder' => $record,
                            'variance' => app(WorkOrderMaterialVarianceService::class)->for($record),
                        ]))
                        ->visible(fn (WorkOrder $record) => auth()->user()?->can('view', $record) ?? false),
                ])
                    ->tooltip(__('resources.common.actions')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWorkOrders::route('/'),
            'create' => Pages\CreateWorkOrder::route('/create'),
            'edit' => Pages\EditWorkOrder::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'project:id,name',
                'assignedTo:id,name',
                'qaApprovedBy:id,name',
                'outputs.item:id,name,sku',
            ])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
