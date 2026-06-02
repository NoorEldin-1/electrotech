<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\InstallationResource\Pages;
use App\Models\Installation;
use App\Services\InstallationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * مرحلة التركيب — Installations (سلايد 2). Tracks the installation stage;
 * expenses load onto the cost center via GL (account 5020) tagged to the
 * operation.
 */
class InstallationResource extends Resource
{
    protected static ?string $model = Installation::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?int $navigationSort = 9;

    protected static ?string $recordTitleAttribute = 'id';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.general_management');
    }

    public static function getLabel(): string
    {
        return __('resources.installations.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.installations.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.installations.navigation_label');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('resources.installations.sections.details'))
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('project_id')
                        ->label(__('resources.installations.fields.operation'))
                        ->relationship('project', 'name')
                        ->searchable(['name', 'code'])
                        ->preload()
                        ->required(),

                    Forms\Components\Select::make('delivery_voucher_id')
                        ->label(__('resources.installations.fields.delivery_voucher'))
                        ->relationship('deliveryVoucher', 'voucher_number')
                        ->searchable()
                        ->preload()
                        ->nullable(),

                    Forms\Components\Textarea::make('notes')
                        ->label(__('resources.installations.fields.notes'))
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('project.name')
                    ->label(__('resources.installations.columns.operation'))
                    ->searchable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('resources.installations.columns.status'))
                    ->badge(),
                Tables\Columns\TextColumn::make('started_at')
                    ->label(__('resources.installations.columns.started_at'))
                    ->dateTime()
                    ->placeholder(__('resources.common.no_data')),
                Tables\Columns\TextColumn::make('completed_at')
                    ->label(__('resources.installations.columns.completed_at'))
                    ->dateTime()
                    ->placeholder(__('resources.common.no_data')),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('start')
                    ->label(__('resources.installations.actions.start'))
                    ->icon('heroicon-o-play')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Installation $record) => $record->isPending()
                        && (auth()->user()?->can('installations.manage') ?? false))
                    ->action(fn (Installation $record) => static::runStage(
                        fn () => app(InstallationService::class)->start($record),
                        __('resources.installations.notifications.started'),
                    )),

                Tables\Actions\Action::make('complete')
                    ->label(__('resources.installations.actions.complete'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Installation $record) => $record->isInProgress()
                        && (auth()->user()?->can('installations.manage') ?? false))
                    ->action(fn (Installation $record) => static::runStage(
                        fn () => app(InstallationService::class)->complete($record),
                        __('resources.installations.notifications.completed'),
                    )),

                Tables\Actions\EditAction::make(),
            ]);
    }

    protected static function runStage(callable $transition, string $successMessage): void
    {
        try {
            $transition();
            Notification::make()->title($successMessage)->success()->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('resources.common.action_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInstallations::route('/'),
            'create' => Pages\CreateInstallation::route('/create'),
            'edit' => Pages\EditInstallation::route('/{record}/edit'),
        ];
    }
}
