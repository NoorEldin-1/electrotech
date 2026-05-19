<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityResource\Pages;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 99;

    protected static ?string $recordTitleAttribute = 'description';

    /**
     * Map of FQCNs we expect as `subject_type` / `causer_type` to the
     * translation key whose value provides a localized human label.
     */
    protected const SUBJECT_TYPE_LABELS = [
        \App\Models\Project::class => 'resources.projects.label',
        \App\Models\Item::class => 'resources.items.label',
        \App\Models\Bom::class => 'resources.boms.label',
        \App\Models\BomItem::class => 'resources.boms.label',
        \App\Models\PurchaseOrder::class => 'resources.purchase_orders.label',
        \App\Models\PurchaseOrderItem::class => 'resources.purchase_orders.label',
        \App\Models\WorkOrder::class => 'resources.work_orders.label',
        \App\Models\Inventory::class => 'resources.items.label',
        \App\Models\InventoryTransaction::class => 'resources.inventory_transactions.label',
        \App\Models\User::class => 'resources.users.label',
    ];

    /**
     * Map of FQCNs to the lang-file key prefix that holds their field
     * label translations. Used when translating attribute names in the
     * Old/New value tables on the view page.
     */
    protected const SUBJECT_TYPE_FIELD_PREFIXES = [
        \App\Models\Project::class => 'projects',
        \App\Models\Item::class => 'items',
        \App\Models\Bom::class => 'boms',
        \App\Models\BomItem::class => 'boms',
        \App\Models\PurchaseOrder::class => 'purchase_orders',
        \App\Models\PurchaseOrderItem::class => 'purchase_orders',
        \App\Models\WorkOrder::class => 'work_orders',
        \App\Models\Inventory::class => 'items',
        \App\Models\InventoryTransaction::class => 'inventory_transactions',
        \App\Models\User::class => 'users',
    ];

    /**
     * Per-model map of enum-backed fields to their `resources.enums.*`
     * translation group, used to translate stored enum values in the
     * Old/New value tables.
     */
    protected const ENUM_VALUE_GROUPS = [
        \App\Models\Project::class => [
            'status' => 'project_status',
        ],
        \App\Models\Item::class => [
            'type' => 'item_type',
            'unit' => 'unit_of_measure',
        ],
        \App\Models\Bom::class => [
            'status' => 'bom_status',
        ],
        \App\Models\PurchaseOrder::class => [
            'status' => 'purchase_order_status',
        ],
        \App\Models\WorkOrder::class => [
            'status' => 'work_order_status',
        ],
        \App\Models\InventoryTransaction::class => [
            'type' => 'transaction_type',
        ],
    ];

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.system');
    }

    public static function getLabel(): string
    {
        return __('resources.activities.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.activities.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.activities.navigation_label');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('activity_log.view') ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['causer', 'subject']);
    }

    /**
     * Provide a translated record title so the breadcrumb and ViewRecord
     * heading don't show the raw English description that was stored at
     * write time (e.g., "WO #... was updated").
     */
    public static function getRecordTitle(?Model $record): string | Htmlable | null
    {
        if (! $record instanceof Activity) {
            return parent::getRecordTitle($record);
        }

        return static::formatDescription($record);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('resources.activities.columns.timestamp'))
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->since()
                    ->tooltip(fn (Activity $record): string => $record->created_at?->format('Y-m-d H:i:s') ?? ''),

                Tables\Columns\TextColumn::make('log_name')
                    ->label(__('resources.activities.columns.log_name'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => static::formatLogName($state))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('event')
                    ->label(__('resources.activities.columns.event'))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        'restored' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => static::formatEvent($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->label(__('resources.activities.columns.description'))
                    ->formatStateUsing(fn (Activity $record): string => static::formatDescription($record))
                    ->wrap()
                    ->searchable()
                    ->limit(80),

                Tables\Columns\TextColumn::make('subject_type')
                    ->label(__('resources.activities.columns.subject'))
                    ->formatStateUsing(fn (?string $state, Activity $record): string => static::formatSubject($state, $record))
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('causer.name')
                    ->label(__('resources.activities.columns.causer'))
                    ->default(__('resources.activities.system_causer'))
                    ->searchable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('event')
                    ->label(__('resources.activities.columns.event'))
                    ->options(fn (): array => [
                        'created' => __('resources.activities.events.created'),
                        'updated' => __('resources.activities.events.updated'),
                        'deleted' => __('resources.activities.events.deleted'),
                        'restored' => __('resources.activities.events.restored'),
                    ])
                    ->multiple(),

                Tables\Filters\SelectFilter::make('log_name')
                    ->label(__('resources.activities.columns.log_name'))
                    ->options(fn (): array => Activity::query()
                        ->whereNotNull('log_name')
                        ->distinct()
                        ->pluck('log_name')
                        ->mapWithKeys(fn (string $log) => [$log => static::formatLogName($log)])
                        ->toArray())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('subject_type')
                    ->label(__('resources.activities.columns.subject'))
                    ->options(fn (): array => Activity::query()
                        ->whereNotNull('subject_type')
                        ->distinct()
                        ->pluck('subject_type')
                        ->mapWithKeys(fn (string $type) => [$type => static::translateSubjectType($type)])
                        ->toArray())
                    ->multiple(),

                Tables\Filters\Filter::make('created_at')
                    ->label(__('resources.activities.filters.date_range'))
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')
                            ->label(__('resources.activities.filters.from')),
                        \Filament\Forms\Components\DatePicker::make('until')
                            ->label(__('resources.activities.filters.until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([])
            ->persistFiltersInSession()
            ->striped()
            ->poll('30s');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make(__('resources.activities.sections.summary'))
                    ->icon('heroicon-o-information-circle')
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('created_at')
                            ->label(__('resources.activities.columns.timestamp'))
                            ->dateTime('Y-m-d H:i:s'),

                        Infolists\Components\TextEntry::make('event')
                            ->label(__('resources.activities.columns.event'))
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => static::formatEvent($state))
                            ->color(fn (?string $state): string => match ($state) {
                                'created' => 'success',
                                'updated' => 'warning',
                                'deleted' => 'danger',
                                'restored' => 'info',
                                default => 'gray',
                            }),

                        Infolists\Components\TextEntry::make('log_name')
                            ->label(__('resources.activities.columns.log_name'))
                            ->badge()
                            ->color('gray')
                            ->formatStateUsing(fn (?string $state): string => static::formatLogName($state)),

                        Infolists\Components\TextEntry::make('batch_uuid')
                            ->label(__('resources.activities.columns.batch'))
                            ->copyable()
                            ->default(__('resources.common.no_data')),

                        Infolists\Components\TextEntry::make('description')
                            ->label(__('resources.activities.columns.description'))
                            ->state(fn (Activity $record): string => static::formatDescription($record))
                            ->columnSpanFull(),
                    ]),

                Infolists\Components\Section::make(__('resources.activities.sections.actor'))
                    ->icon('heroicon-o-user-circle')
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('causer.name')
                            ->label(__('resources.activities.columns.causer'))
                            ->default(__('resources.activities.system_causer')),

                        Infolists\Components\TextEntry::make('causer_type')
                            ->label(__('resources.activities.columns.causer_type'))
                            ->formatStateUsing(fn (?string $state): string => $state
                                ? static::translateSubjectType($state)
                                : __('resources.common.no_data')),

                        Infolists\Components\TextEntry::make('subject_type')
                            ->label(__('resources.activities.columns.subject'))
                            ->formatStateUsing(fn (?string $state, Activity $record): string => static::formatSubject($state, $record)),

                        Infolists\Components\TextEntry::make('subject_id')
                            ->label(__('resources.activities.columns.subject_id'))
                            ->default(__('resources.common.no_data')),
                    ]),

                Infolists\Components\Section::make(__('resources.activities.sections.changes'))
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Activity $record): bool => static::hasOldValues($record) || static::hasNewValues($record))
                    ->schema([
                        Infolists\Components\KeyValueEntry::make('changes.old')
                            ->label(__('resources.activities.columns.old_values'))
                            ->visible(fn (Activity $record): bool => static::hasOldValues($record))
                            ->state(fn (Activity $record): array => static::translateChangeSet(
                                $record->subject_type,
                                static::collectionToArray($record->changes()->get('old')),
                            )),

                        Infolists\Components\KeyValueEntry::make('changes.new')
                            ->label(__('resources.activities.columns.new_values'))
                            ->visible(fn (Activity $record): bool => static::hasNewValues($record))
                            ->state(fn (Activity $record): array => static::translateChangeSet(
                                $record->subject_type,
                                static::collectionToArray($record->changes()->get('attributes')),
                            )),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivities::route('/'),
            'view' => Pages\ViewActivity::route('/{record}'),
        ];
    }

    /**
     * Build a fully-translated description for a stored activity row.
     * Falls back to the raw `description` column when we don't have
     * enough metadata to reconstruct a sentence.
     */
    public static function formatDescription(Activity $record): string
    {
        if (! $record->event || ! $record->subject_type) {
            return (string) ($record->description ?? __('resources.common.no_data'));
        }

        $subject = static::formatSubject($record->subject_type, $record);
        $event = static::formatEvent($record->event);

        return trim(__('resources.activities.description_format', [
            'subject' => $subject,
            'event' => $event,
        ]));
    }

    /**
     * Translate an `event` string to the user's language. Falls back to
     * a Title-cased copy of the raw event for unknown event types.
     */
    public static function formatEvent(?string $event): string
    {
        if (! $event) {
            return __('resources.common.no_data');
        }

        $key = 'resources.activities.events.' . $event;
        $translated = __($key);

        return $translated === $key ? ucfirst($event) : $translated;
    }

    /**
     * Translate a stored `log_name` value to a friendly label.
     */
    public static function formatLogName(?string $logName): string
    {
        if (! $logName) {
            return __('resources.common.no_data');
        }

        $key = 'resources.activities.log_names.' . $logName;
        $translated = __($key);

        return $translated === $key ? $logName : $translated;
    }

    /**
     * Map a FQCN like `App\Models\WorkOrder` to its translated label
     * (e.g. "أمر تشغيل"). Falls back to the class basename when no
     * translation key is registered for it.
     */
    public static function translateSubjectType(?string $type): string
    {
        if (! $type) {
            return __('resources.common.no_data');
        }

        $key = self::SUBJECT_TYPE_LABELS[$type] ?? null;

        if ($key === null) {
            return class_basename($type);
        }

        return __($key);
    }

    /**
     * Convert a raw {attribute => value} array (as stored in the
     * activity's `properties.old` / `properties.attributes`) into a
     * {translated_label => translated_value} array, applying field-name
     * translation and enum-value translation per the model's mapping.
     */
    public static function translateChangeSet(?string $subjectType, array $values): array
    {
        if (empty($values)) {
            return $values;
        }

        $prefix = self::SUBJECT_TYPE_FIELD_PREFIXES[$subjectType ?? ''] ?? null;
        $enumMap = self::ENUM_VALUE_GROUPS[$subjectType ?? ''] ?? [];

        $translated = [];

        foreach ($values as $key => $value) {
            $label = static::translateFieldLabel($prefix, (string) $key);
            $displayValue = static::translateFieldValue($enumMap[$key] ?? null, $value);
            $translated[$label] = $displayValue;
        }

        return $translated;
    }

    public static function translateFieldLabel(?string $prefix, string $field): string
    {
        $candidates = [];

        if ($prefix) {
            $candidates[] = "resources.{$prefix}.fields.{$field}";
            $candidates[] = "resources.{$prefix}.columns.{$field}";
        }

        // Generic activity-log bucket — covers fields that aren't in the
        // per-model fields/columns translations (e.g. timestamps,
        // foreign keys, inventory/attachment attributes).
        $candidates[] = "resources.activities.field_labels.{$field}";

        foreach ($candidates as $candidate) {
            $translated = __($candidate);

            if ($translated !== $candidate && is_string($translated)) {
                return $translated;
            }
        }

        // Final fallback: humanize the raw snake_case key.
        return Str::headline($field);
    }

    public static function translateFieldValue(?string $enumGroup, mixed $value): mixed
    {
        if ($value === null) {
            return __('resources.common.no_data');
        }

        if (is_bool($value)) {
            return $value ? __('resources.activities.values.true') : __('resources.activities.values.false');
        }

        if ($enumGroup === null) {
            return $value;
        }

        $stringValue = $value instanceof \BackedEnum
            ? $value->value
            : (string) $value;

        $key = "resources.enums.{$enumGroup}.{$stringValue}";
        $translated = __($key);

        return $translated === $key ? $value : $translated;
    }

    protected static function formatSubject(?string $type, Activity $record): string
    {
        if (! $type) {
            return __('resources.common.no_data');
        }

        $label = static::translateSubjectType($type);

        return $record->subject_id
            ? "{$label} #{$record->subject_id}"
            : $label;
    }

    protected static function hasOldValues(Activity $record): bool
    {
        $old = static::collectionToArray($record->changes()->get('old'));

        return ! empty($old);
    }

    protected static function hasNewValues(Activity $record): bool
    {
        $new = static::collectionToArray($record->changes()->get('attributes'));

        return ! empty($new);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function collectionToArray(mixed $value): array
    {
        if ($value instanceof Collection) {
            return $value->toArray();
        }

        if (is_array($value)) {
            return $value;
        }

        return [];
    }
}
