<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\ProjectStatus;
use App\Filament\Resources\ProjectResource\Pages;
use App\Models\Project;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static ?string $navigationIcon = 'heroicon-o-briefcase';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.sales_crm');
    }

    public static function getLabel(): string
    {
        return __('resources.projects.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.projects.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.projects.navigation_label');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('resources.projects.sections.project_information'))
                    ->icon('heroicon-o-information-circle')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->label(__('resources.projects.fields.code'))
                            ->default(fn () => Project::generateCode())
                            ->disabled()
                            ->dehydrated()
                            ->required()
                            ->unique(ignoreRecord: true),

                        Forms\Components\TextInput::make('name')
                            ->label(__('resources.projects.fields.name'))
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('client_name')
                            ->label(__('resources.projects.fields.client_name'))
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('consultant_name')
                            ->label(__('resources.projects.fields.consultant_name'))
                            ->maxLength(255),

                        Forms\Components\Select::make('status')
                            ->label(__('resources.projects.fields.status'))
                            ->options(ProjectStatus::class)
                            ->default(ProjectStatus::Draft)
                            ->required(),

                        Forms\Components\Hidden::make('created_by')
                            ->default(fn () => auth()->id()),
                    ]),

                Forms\Components\Section::make(__('resources.projects.sections.financial_timeline'))
                    ->icon('heroicon-o-currency-dollar')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('estimated_budget')
                            ->label(__('resources.projects.fields.estimated_budget'))
                            ->numeric()
                            ->prefix('EGP')
                            ->default(0),

                        Forms\Components\TextInput::make('actual_cost')
                            ->label(__('resources.projects.fields.actual_cost'))
                            ->numeric()
                            ->prefix('EGP')
                            ->default(0)
                            ->disabled()
                            ->dehydrated(),

                        Forms\Components\DatePicker::make('start_date')
                            ->label(__('resources.projects.fields.start_date')),
                        Forms\Components\DatePicker::make('end_date')
                            ->label(__('resources.projects.fields.end_date'))
                            ->after('start_date'),
                    ]),

                Forms\Components\Section::make(__('resources.projects.sections.description'))
                    ->schema([
                        Forms\Components\Textarea::make('description')
                            ->label(__('resources.projects.fields.description'))
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label(__('resources.projects.columns.code'))
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('name')
                    ->label(__('resources.projects.columns.name'))
                    ->searchable()
                    ->sortable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('client_name')
                    ->label(__('resources.projects.columns.client_name'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('resources.projects.columns.status'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('estimated_budget')
                    ->label(__('resources.projects.columns.estimated_budget'))
                    ->money('EGP')
                    ->sortable(),

                Tables\Columns\TextColumn::make('start_date')
                    ->label(__('resources.projects.columns.start_date'))
                    ->date()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label(__('resources.projects.columns.created_by'))
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('resources.projects.columns.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('resources.projects.columns.status'))
                    ->options(ProjectStatus::class)
                    ->multiple(),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjects::route('/'),
            'create' => Pages\CreateProject::route('/create'),
            'edit' => Pages\EditProject::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['createdBy:id,name'])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
