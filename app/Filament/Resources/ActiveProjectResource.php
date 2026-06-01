<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\ProjectStatus;
use App\Filament\Resources\ActiveProjectResource\Pages;
use App\Models\Project;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ActiveProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static ?string $navigationIcon = 'heroicon-o-play';

    protected static ?int $navigationSort = 13;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.sales_crm');
    }

    public static function getLabel(): string
    {
        return __('resources.active_projects.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.active_projects.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.active_projects.navigation_label');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) Project::query()->where('status', ProjectStatus::InProgress)->count();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('code')
                    ->label(__('resources.active_projects.columns.code'))
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('resources.active_projects.columns.name'))
                    ->searchable()
                    ->limit(40),
                Tables\Columns\TextColumn::make('client_name')
                    ->label(__('resources.active_projects.columns.client_name'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('actual_cost')
                    ->label(__('resources.active_projects.columns.actual_cost'))
                    ->money('EGP')
                    ->sortable(),
                Tables\Columns\TextColumn::make('start_date')
                    ->label(__('resources.active_projects.columns.start_date'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('latestOffer.financial_amount')
                    ->label(__('resources.active_projects.columns.winning_offer'))
                    ->money('EGP')
                    ->placeholder(__('resources.common.no_data')),
            ])
            ->defaultSort('start_date', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->url(fn (Project $r) => ProjectResource::getUrl('edit', ['record' => $r])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActiveProjects::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('status', ProjectStatus::InProgress)
            ->with(['latestOffer']);
    }
}
