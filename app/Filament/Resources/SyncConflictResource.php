<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SyncConflictResource\Pages;
use App\Models\SyncConflict;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin view of sync conflicts.
 *
 * A conflict appears here when the server's OperationProcessor rejected
 * or arbitrated away a client-submitted operation. This resource is the
 * supervisor's window into "things that didn't quite line up between
 * the floor and the office".
 *
 * Two actions:
 *   - View — show the client payload vs the server's current state
 *     side by side. Read-only diff.
 *   - Resolve — mark the conflict as acknowledged, recording who and
 *     when. Does NOT re-apply the client's payload — by the time we're
 *     here, the server's state is what's correct. If a re-action is
 *     needed, the admin does it through the relevant resource UI.
 *
 * We deliberately do NOT provide a "force apply client payload" action.
 * The rejected payload is by definition stale; replaying it would just
 * trample whatever the server already accepted. Forensic data only.
 */
class SyncConflictResource extends Resource
{
    protected static ?string $model = SyncConflict::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?int $navigationSort = 73;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.system');
    }

    public static function getLabel(): string
    {
        return __('resources.sync_conflicts.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.sync_conflicts.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.sync_conflicts.navigation_label');
    }

    public static function getEloquentQuery(): Builder
    {
        // Default to unresolved first so the queue is actionable.
        return parent::getEloquentQuery()
            ->with(['user', 'deviceToken', 'resolver'])
            ->orderByRaw('resolved_at IS NULL DESC')
            ->orderBy('created_at', 'desc');
    }

    public static function form(Form $form): Form
    {
        // The resource is read-only — Filament still needs a form for
        // the View page. All fields are disabled.
        return $form->schema([
            Forms\Components\TextInput::make('uuid')
                ->label(__('resources.sync_conflicts.fields.uuid'))->disabled(),
            Forms\Components\TextInput::make('model_type')
                ->label(__('resources.sync_conflicts.fields.model_type'))->disabled(),
            Forms\Components\TextInput::make('record_uuid')
                ->label(__('resources.sync_conflicts.fields.record_uuid'))->disabled(),
            Forms\Components\TextInput::make('reason')
                ->label(__('resources.sync_conflicts.fields.reason'))->disabled(),
            Forms\Components\TextInput::make('server_version')
                ->label(__('resources.sync_conflicts.fields.server_version'))->disabled()->numeric(),
            Forms\Components\TextInput::make('client_base_version')
                ->label(__('resources.sync_conflicts.fields.client_base_version'))->disabled()->numeric(),
            Forms\Components\Textarea::make('error_message')
                ->label(__('resources.sync_conflicts.fields.error_message'))
                ->disabled()->rows(2)->columnSpanFull(),
            Forms\Components\Textarea::make('client_payload_pretty')
                ->label(__('resources.sync_conflicts.fields.client_payload'))
                ->disabled()
                ->rows(10)
                ->columnSpanFull()
                ->afterStateHydrated(function (Forms\Components\Textarea $component, $state, $record) {
                    if ($record) {
                        $component->state(json_encode($record->client_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    }
                }),
            Forms\Components\Textarea::make('server_state_pretty')
                ->label(__('resources.sync_conflicts.fields.server_state'))
                ->disabled()
                ->rows(10)
                ->columnSpanFull()
                ->afterStateHydrated(function (Forms\Components\Textarea $component, $state, $record) {
                    if ($record) {
                        $component->state(json_encode($record->server_state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    }
                }),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('resources.sync_conflicts.columns.detected_at'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('resources.sync_conflicts.columns.operator'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('deviceToken.device_name')
                    ->label(__('resources.sync_conflicts.columns.device'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('model_type')
                    ->label(__('resources.sync_conflicts.columns.type'))
                    ->formatStateUsing(fn (string $state) => class_basename($state))
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('record_uuid')
                    ->label(__('resources.sync_conflicts.columns.record'))
                    ->limit(8)
                    ->tooltip(fn ($state) => $state),
                Tables\Columns\TextColumn::make('reason')
                    ->label(__('resources.sync_conflicts.columns.reason'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('resources.sync_conflicts.reasons.' . $state, [], app()->getLocale()) ?: $state)
                    ->color(fn (string $state) => match ($state) {
                        'version_stale'      => 'warning',
                        'illegal_transition' => 'danger',
                        'insufficient_stock' => 'danger',
                        'validation_failed'  => 'danger',
                        'tombstoned'         => 'gray',
                        default              => 'gray',
                    }),
                Tables\Columns\IconColumn::make('resolved_at')
                    ->label(__('resources.sync_conflicts.columns.resolved'))
                    ->boolean()
                    ->getStateUsing(fn ($record) => $record->resolved_at !== null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('reason')
                    ->label(__('resources.sync_conflicts.filters.reason'))
                    ->options([
                        'version_stale'      => __('resources.sync_conflicts.reasons.version_stale'),
                        'illegal_transition' => __('resources.sync_conflicts.reasons.illegal_transition'),
                        'insufficient_stock' => __('resources.sync_conflicts.reasons.insufficient_stock'),
                        'validation_failed'  => __('resources.sync_conflicts.reasons.validation_failed'),
                        'fk_missing'         => __('resources.sync_conflicts.reasons.fk_missing'),
                        'push_rejected'      => __('resources.sync_conflicts.reasons.push_rejected'),
                    ]),
                Tables\Filters\TernaryFilter::make('resolved')
                    ->label(__('resources.sync_conflicts.filters.resolved'))
                    ->trueLabel(__('resources.sync_conflicts.filters.resolved_true'))
                    ->falseLabel(__('resources.sync_conflicts.filters.resolved_false'))
                    ->queries(
                        true:  fn (Builder $q) => $q->whereNotNull('resolved_at'),
                        false: fn (Builder $q) => $q->whereNull('resolved_at'),
                        blank: fn (Builder $q) => $q,
                    ),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\Action::make('resolve')
                        ->label(__('resources.sync_conflicts.actions.resolve'))
                        ->icon('heroicon-o-check')
                        ->visible(fn ($record) => $record->resolved_at === null)
                        ->requiresConfirmation()
                        ->modalDescription(__('resources.sync_conflicts.actions.resolve_confirmation'))
                        ->action(function ($record): void {
                            $record->update([
                                'resolved_at' => now(),
                                'resolved_by' => auth()->id(),
                                'resolution'  => 'accepted_server',
                            ]);
                        }),
                ])
                    ->tooltip(__('resources.common.actions')),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSyncConflicts::route('/'),
            'view'  => Pages\ViewSyncConflict::route('/{record}'),
        ];
    }

    // Hide create — conflicts are only authored by the OperationProcessor.
    public static function canCreate(): bool
    {
        return false;
    }
}
