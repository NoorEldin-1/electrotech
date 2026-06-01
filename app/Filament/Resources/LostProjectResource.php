<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\LostReason;
use App\Enums\ProjectStatus;
use App\Filament\Resources\LostProjectResource\Pages;
use App\Models\Project;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LostProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static ?string $navigationIcon = 'heroicon-o-no-symbol';

    protected static ?int $navigationSort = 14;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.sales_crm');
    }

    public static function getLabel(): string
    {
        return __('resources.lost_projects.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.lost_projects.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.lost_projects.navigation_label');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) Project::query()->where('status', ProjectStatus::Lost)->count();
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
                Tables\Columns\TextColumn::make('name')
                    ->label(__('resources.lost_projects.columns.name'))
                    ->searchable()
                    ->limit(40),
                Tables\Columns\TextColumn::make('client_name')
                    ->label(__('resources.lost_projects.columns.client_name'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('lost_reason')
                    ->label(__('resources.lost_projects.columns.lost_reason'))
                    ->badge(),
                Tables\Columns\TextColumn::make('winning_competitor')
                    ->label(__('resources.lost_projects.columns.winning_competitor'))
                    ->searchable()
                    ->placeholder(__('resources.common.no_data')),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('resources.lost_projects.columns.date'))
                    ->date()
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('lost_reason')
                    ->label(__('resources.lost_projects.columns.lost_reason'))
                    ->options(LostReason::class)
                    ->multiple(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->url(fn (Project $r) => ProjectResource::getUrl('edit', ['record' => $r])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLostProjects::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('status', ProjectStatus::Lost);
    }
}
