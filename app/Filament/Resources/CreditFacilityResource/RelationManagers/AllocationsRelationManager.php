<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditFacilityResource\RelationManagers;

use App\Models\CreditFacility;
use App\Models\Project;
use App\Services\CreditFacilityService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * توزيع التسهيل على العمليات — allocations routed through CreditFacilityService
 * so the available-limit check fires (سلايد 1: "وتحليلها على العمليات").
 */
class AllocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'allocations';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('resources.facility_allocations.plural_label');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('project.name')
                    ->label(__('resources.facility_allocations.columns.operation'))
                    ->limit(30),
                Tables\Columns\TextColumn::make('allocated_amount')
                    ->label(__('resources.facility_allocations.columns.amount'))
                    ->numeric(2),
                Tables\Columns\TextColumn::make('allocated_at')
                    ->label(__('resources.facility_allocations.columns.allocated_at'))
                    ->date(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('resources.facility_allocations.columns.status'))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('allocate')
                    ->label(__('resources.facility_allocations.actions.allocate'))
                    ->icon('heroicon-o-plus')
                    ->visible(fn () => auth()->user()?->can('credit_facilities.manage') ?? false)
                    ->form([
                        Forms\Components\Select::make('project_id')
                            ->label(__('resources.facility_allocations.fields.operation'))
                            ->options(fn () => Project::query()->orderByDesc('id')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        Forms\Components\TextInput::make('allocated_amount')
                            ->label(__('resources.facility_allocations.fields.amount'))
                            ->numeric()
                            ->minValue(0.01)
                            ->required(),
                        Forms\Components\TextInput::make('notes')
                            ->label(__('resources.facility_allocations.fields.notes'))
                            ->maxLength(255),
                    ])
                    ->action(function (array $data): void {
                        /** @var CreditFacility $facility */
                        $facility = $this->getOwnerRecord();

                        try {
                            app(CreditFacilityService::class)->allocate(
                                facility: $facility,
                                project: Project::findOrFail($data['project_id']),
                                amount: (float) $data['allocated_amount'],
                                notes: $data['notes'] ?? null,
                            );
                            Notification::make()
                                ->title(__('resources.facility_allocations.notifications.allocated'))
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title(__('resources.common.action_failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('release')
                    ->label(__('resources.facility_allocations.actions.release'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn ($record) => $record->isActive()
                        && (auth()->user()?->can('credit_facilities.manage') ?? false))
                    ->action(function ($record): void {
                        app(CreditFacilityService::class)->release($record);
                        Notification::make()
                            ->title(__('resources.facility_allocations.notifications.released'))
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
