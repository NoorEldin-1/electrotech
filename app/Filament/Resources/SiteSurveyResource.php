<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SiteSurveyResource\Pages;
use App\Models\SiteSurvey;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * معاينات الموقع — Site Surveys (سلايد 1: مقاسات الموقع والرسومات). Drawings /
 * measurement files attach to the project (categories drowing / site_measurement).
 */
class SiteSurveyResource extends Resource
{
    protected static ?string $model = SiteSurvey::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'id';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.general_management');
    }

    public static function getLabel(): string
    {
        return __('resources.site_surveys.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.site_surveys.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.site_surveys.navigation_label');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('resources.site_surveys.sections.details'))
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('project_id')
                        ->label(__('resources.site_surveys.fields.operation'))
                        ->relationship('project', 'name')
                        ->searchable(['name', 'code'])
                        ->preload()
                        ->required(),

                    Forms\Components\DatePicker::make('survey_date')
                        ->label(__('resources.site_surveys.fields.survey_date'))
                        ->default(now())
                        ->required(),

                    Forms\Components\Select::make('surveyed_by')
                        ->label(__('resources.site_surveys.fields.surveyed_by'))
                        ->relationship('surveyedBy', 'name')
                        ->searchable()
                        ->preload()
                        ->nullable(),

                    Forms\Components\Textarea::make('measurements')
                        ->label(__('resources.site_surveys.fields.measurements'))
                        ->rows(4)
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('notes')
                        ->label(__('resources.site_surveys.fields.notes'))
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('project.name')
                    ->label(__('resources.site_surveys.columns.operation'))
                    ->searchable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('survey_date')
                    ->label(__('resources.site_surveys.columns.survey_date'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('surveyedBy.name')
                    ->label(__('resources.site_surveys.columns.surveyed_by'))
                    ->placeholder(__('resources.common.no_data')),
            ])
            ->defaultSort('survey_date', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSiteSurveys::route('/'),
            'create' => Pages\CreateSiteSurvey::route('/create'),
            'edit' => Pages\EditSiteSurvey::route('/{record}/edit'),
        ];
    }
}
