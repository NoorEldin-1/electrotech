<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DeliveryMinuteResource\Pages;
use App\Models\DeliveryMinute;
use App\Services\DeliveryMinuteService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * محاضر التسليم — Delivery Minutes (سلايد 2). Recorded on delivery and
 * distributed to all departments via the topbar bell.
 */
class DeliveryMinuteResource extends Resource
{
    protected static ?string $model = DeliveryMinute::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'minute_number';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.general_management');
    }

    public static function getLabel(): string
    {
        return __('resources.delivery_minutes.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.delivery_minutes.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.delivery_minutes.navigation_label');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('resources.delivery_minutes.sections.details'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('minute_number')
                        ->label(__('resources.delivery_minutes.fields.minute_number'))
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder(__('resources.common.auto_generated')),

                    Forms\Components\DatePicker::make('minute_date')
                        ->label(__('resources.delivery_minutes.fields.minute_date'))
                        ->default(now())
                        ->required(),

                    Forms\Components\Select::make('project_id')
                        ->label(__('resources.delivery_minutes.fields.operation'))
                        ->relationship('project', 'name')
                        ->searchable(['name', 'code'])
                        ->preload()
                        ->required(),

                    Forms\Components\Select::make('delivery_voucher_id')
                        ->label(__('resources.delivery_minutes.fields.delivery_voucher'))
                        ->relationship('deliveryVoucher', 'voucher_number')
                        ->searchable()
                        ->preload()
                        ->nullable(),

                    Forms\Components\Select::make('customer_id')
                        ->label(__('resources.delivery_minutes.fields.customer'))
                        ->relationship('customer', 'name')
                        ->searchable()
                        ->preload()
                        ->nullable(),

                    Forms\Components\Textarea::make('content')
                        ->label(__('resources.delivery_minutes.fields.content'))
                        ->rows(4)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('minute_number')
                    ->label(__('resources.delivery_minutes.columns.minute_number'))
                    ->searchable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('project.name')
                    ->label(__('resources.delivery_minutes.columns.operation'))
                    ->searchable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('customer.name')
                    ->label(__('resources.delivery_minutes.columns.customer'))
                    ->placeholder(__('resources.common.no_data')),
                Tables\Columns\TextColumn::make('minute_date')
                    ->label(__('resources.delivery_minutes.columns.minute_date'))
                    ->date()
                    ->sortable(),
                Tables\Columns\IconColumn::make('distributed_at')
                    ->label(__('resources.delivery_minutes.columns.distributed'))
                    ->boolean()
                    ->state(fn (DeliveryMinute $record): bool => $record->isDistributed()),
            ])
            ->defaultSort('minute_date', 'desc')
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('distribute')
                        ->label(__('resources.delivery_minutes.actions.distribute'))
                        ->icon('heroicon-o-paper-airplane')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (DeliveryMinute $record) => ! $record->isDistributed()
                            && auth()->user()?->can('distribute', $record))
                        ->action(function (DeliveryMinute $record): void {
                            app(DeliveryMinuteService::class)->distribute($record);
                            Notification::make()
                                ->title(__('resources.delivery_minutes.notifications.distributed'))
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\EditAction::make()
                        ->visible(fn (DeliveryMinute $record) => ! $record->isDistributed()),
                ])
                    ->tooltip(__('resources.common.actions')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeliveryMinutes::route('/'),
            'create' => Pages\CreateDeliveryMinute::route('/create'),
            'edit' => Pages\EditDeliveryMinute::route('/{record}/edit'),
        ];
    }
}
