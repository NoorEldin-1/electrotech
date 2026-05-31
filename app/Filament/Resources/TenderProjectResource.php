<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\LostReason;
use App\Enums\ProjectStatus;
use App\Filament\Resources\TenderProjectResource\Pages;
use App\Models\Project;
use App\Services\SalesPipelineService;
use DomainException;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TenderProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.sales_crm');
    }

    public static function getLabel(): string
    {
        return __('resources.tender_projects.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.tender_projects.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.tender_projects.navigation_label');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) Project::query()->where('status', ProjectStatus::Tender)->count();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label(__('resources.tender_projects.columns.name'))
                    ->searchable()
                    ->sortable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('latestOffer.financial_amount')
                    ->label(__('resources.tender_projects.columns.financial_offer'))
                    ->money('EGP')
                    ->placeholder(__('resources.common.no_data')),

                Tables\Columns\TextColumn::make('latestOffer.technical_amount')
                    ->label(__('resources.tender_projects.columns.technical_offer'))
                    ->money('EGP')
                    ->placeholder(__('resources.common.no_data')),

                Tables\Columns\IconColumn::make('alarm_at')
                    ->label(__('resources.tender_projects.columns.alarm'))
                    ->boolean()
                    ->getStateUsing(fn (Project $r): bool => $r->alarm_at !== null)
                    ->trueIcon('heroicon-o-bell-alert')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('warning'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('resources.tender_projects.columns.date'))
                    ->date()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->url(fn (Project $r) => ProjectResource::getUrl('edit', ['record' => $r])),

                Tables\Actions\Action::make('action')
                    ->label(__('resources.tender_projects.actions.action'))
                    ->icon('heroicon-o-arrow-right-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('resources.tender_projects.actions.action_modal_heading'))
                    ->modalDescription(__('resources.tender_projects.actions.action_modal_description'))
                    ->form([
                        Forms\Components\Textarea::make('smb_note')
                            ->label(__('resources.tender_projects.fields.smb_note'))
                            ->rows(3),
                    ])
                    ->visible(fn (Project $r) => auth()->user()?->can('moveToInHand', $r) ?? false)
                    ->action(function (Project $r, array $data) {
                        try {
                            app(SalesPipelineService::class)->moveToInHand($r, $data['smb_note'] ?? null);
                            Notification::make()
                                ->success()
                                ->title(__('resources.tender_projects.notifications.moved_to_inhand'))
                                ->send();
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),

                Tables\Actions\Action::make('cancel')
                    ->label(__('resources.tender_projects.actions.cancel'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('resources.tender_projects.actions.cancel_modal_heading'))
                    ->form([
                        Forms\Components\Select::make('reason')
                            ->label(__('resources.tender_projects.fields.lost_reason'))
                            ->options(LostReason::class)
                            ->required(),
                        Forms\Components\Textarea::make('note')
                            ->label(__('resources.tender_projects.fields.lost_reason_note'))
                            ->rows(3),
                        Forms\Components\TextInput::make('winning_competitor')
                            ->label(__('resources.tender_projects.fields.winning_competitor'))
                            ->maxLength(255),
                    ])
                    ->visible(fn (Project $r) => auth()->user()?->can('cancelToLost', $r) ?? false)
                    ->action(function (Project $r, array $data) {
                        try {
                            app(SalesPipelineService::class)->cancelToLost(
                                $r,
                                LostReason::from($data['reason']),
                                $data['note'] ?? null,
                                $data['winning_competitor'] ?? null,
                            );
                            Notification::make()
                                ->success()
                                ->title(__('resources.tender_projects.notifications.moved_to_lost'))
                                ->send();
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),

                Tables\Actions\Action::make('alarm')
                    ->label(__('resources.tender_projects.actions.set_alarm'))
                    ->icon('heroicon-o-bell-alert')
                    ->color('warning')
                    ->form([
                        Forms\Components\DateTimePicker::make('alarm_at')
                            ->label(__('resources.tender_projects.fields.alarm_at'))
                            ->required(),
                        Forms\Components\TextInput::make('alarm_note')
                            ->label(__('resources.tender_projects.fields.alarm_note'))
                            ->maxLength(255),
                    ])
                    ->visible(fn (Project $r) => auth()->user()?->can('setAlarm', $r) ?? false)
                    ->action(function (Project $r, array $data) {
                        app(SalesPipelineService::class)->setAlarm(
                            $r,
                            \Carbon\Carbon::parse($data['alarm_at']),
                            $data['alarm_note'] ?? null,
                        );
                        Notification::make()
                            ->success()
                            ->title(__('resources.tender_projects.notifications.alarm_set'))
                            ->send();
                    }),

                Tables\Actions\Action::make('clear_alarm')
                    ->label(__('resources.tender_projects.actions.clear_alarm'))
                    ->icon('heroicon-o-bell-slash')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (Project $r) => $r->alarm_at !== null
                        && (auth()->user()?->can('setAlarm', $r) ?? false))
                    ->action(function (Project $r) {
                        app(SalesPipelineService::class)->clearAlarm($r);
                        Notification::make()
                            ->success()
                            ->title(__('resources.tender_projects.notifications.alarm_cleared'))
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenderProjects::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('status', ProjectStatus::Tender)
            ->with(['latestOffer']);
    }
}
