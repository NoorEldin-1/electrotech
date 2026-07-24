<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\AttachmentCategory;
use App\Enums\LostReason;
use App\Enums\ProjectStatus;
use App\Filament\Resources\InHandProjectResource\Pages;
use App\Models\Project;
use App\Services\SalesPipelineService;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use DomainException;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InHandProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static ?string $navigationIcon = 'heroicon-o-hand-raised';

    protected static ?int $navigationSort = 12;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.sales_crm');
    }

    public static function getLabel(): string
    {
        return __('resources.in_hand_projects.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.in_hand_projects.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.in_hand_projects.navigation_label');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) Project::query()->where('status', ProjectStatus::InHand)->count();
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
                    ->label(__('resources.in_hand_projects.columns.name'))
                    ->searchable()
                    ->sortable()
                    ->limit(40),

                Tables\Columns\IconColumn::make('missing_offer')
                    ->label(__('resources.sales_alerts.column'))
                    ->getStateUsing(fn (Project $r): bool => ! $r->hasPricedOffer())
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('danger')
                    ->falseColor('success')
                    ->tooltip(fn (Project $r): ?string => $r->hasPricedOffer() ? null : __('resources.sales_alerts.missing_offer_tooltip')),

                // SMB presence indicator (شريحة 11): error when the operation has
                // no SMB / Submittal file on record, verified check once it does —
                // mirroring the "missing offer" alert above. SMB and Submittal are
                // the same artifact, so this reads the Submittal attachments.
                Tables\Columns\IconColumn::make('has_smb')
                    ->label(__('resources.in_hand_projects.columns.smb'))
                    ->getStateUsing(fn (Project $r): bool => $r->hasSmb())
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->tooltip(fn (Project $r): string => $r->hasSmb()
                        ? __('resources.in_hand_projects.smb_present_tooltip')
                        : __('resources.in_hand_projects.smb_missing_tooltip')),

                Tables\Columns\TextColumn::make('acceptance_email_at')
                    ->label(__('resources.in_hand_projects.columns.acceptance_email_at'))
                    ->date()
                    ->placeholder(__('resources.common.no_data')),

                Tables\Columns\IconColumn::make('manager_approved_at')
                    ->label(__('resources.in_hand_projects.columns.manager_approved'))
                    ->boolean()
                    ->getStateUsing(fn (Project $r): bool => $r->manager_approved_at !== null),

                Tables\Columns\IconColumn::make('alarm_at')
                    ->label(__('resources.in_hand_projects.columns.alarm'))
                    ->boolean()
                    ->getStateUsing(fn (Project $r): bool => $r->alarm_at !== null)
                    ->trueIcon('heroicon-o-bell-alert')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('warning'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('resources.in_hand_projects.columns.date'))
                    ->date()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()
                        ->url(fn (Project $r) => ProjectResource::getUrl('edit', ['record' => $r])),

                    // Standalone manager-approval gate (Slide 5): previously the
                    // only way to approve was buried inside the "move to active"
                    // modal and hidden from non-managers, so reviewers couldn't
                    // find it. Surface it as its own action for whoever holds
                    // projects.manager_approve, while the operation is still
                    // awaiting approval.
                    Tables\Actions\Action::make('manager_approve')
                        ->label(__('resources.in_hand_projects.actions.manager_approve'))
                        ->icon('heroicon-o-shield-check')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading(__('resources.in_hand_projects.actions.manager_approve_modal_heading'))
                        ->visible(fn (Project $r): bool => $r->manager_approved_at === null
                            && (auth()->user()?->can('managerApprove', $r) ?? false))
                        ->action(function (Project $r) {
                            app(SalesPipelineService::class)->managerApprove($r);

                            Notification::make()
                                ->success()
                                ->title(__('resources.in_hand_projects.notifications.manager_approved'))
                                ->send();
                        }),

                    Tables\Actions\Action::make('action')
                        ->label(__('resources.in_hand_projects.actions.action'))
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading(__('resources.in_hand_projects.actions.action_modal_heading'))
                        ->modalDescription(__('resources.in_hand_projects.actions.action_modal_description'))
                        ->form([
                            Forms\Components\DatePicker::make('acceptance_email_at')
                                ->label(__('resources.in_hand_projects.fields.acceptance_email_at'))
                                ->default(fn (Project $r) => $r->acceptance_email_at)
                                ->required(),

                            Forms\Components\Toggle::make('manager_approve_now')
                                ->label(__('resources.in_hand_projects.fields.manager_approve_now'))
                                ->helperText(__('resources.in_hand_projects.fields.manager_approve_helper'))
                                ->visible(fn () => auth()->user()?->can('projects.manager_approve') ?? false),

                            // Slide 11: attach the customer acceptance / SMB file
                            // (the document itself, not just a typed note).
                            Forms\Components\FileUpload::make('acceptance_file')
                                ->label(__('resources.in_hand_projects.fields.acceptance_file'))
                                ->helperText(__('resources.in_hand_projects.fields.acceptance_file_helper'))
                                ->disk('public')
                                ->directory(fn (Project $r) => 'attachments/' . $r->id . '/' . AttachmentCategory::CustomerAcceptance->value)
                                ->downloadable()
                                ->openable()
                                ->previewable(false)
                                ->maxSize(40960),
                        ])
                        ->visible(fn (Project $r) => auth()->user()?->can('moveToActive', $r) ?? false)
                        ->action(function (Project $r, array $data) {
                            try {
                                $r->acceptance_email_at = $data['acceptance_email_at'];
                                $r->save();

                                self::storeAcceptanceFile($r, $data['acceptance_file'] ?? null);

                                if (! empty($data['manager_approve_now'])
                                    && (auth()->user()?->can('managerApprove', $r) ?? false)) {
                                    app(SalesPipelineService::class)->managerApprove($r);
                                }

                                app(SalesPipelineService::class)->moveToActive($r->fresh());

                                Notification::make()
                                    ->success()
                                    ->title(__('resources.in_hand_projects.notifications.moved_to_active'))
                                    ->send();
                            } catch (DomainException $e) {
                                Notification::make()->danger()->title($e->getMessage())->send();
                            }
                        }),

                    Tables\Actions\Action::make('cancel')
                        ->label(__('resources.in_hand_projects.actions.cancel'))
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(__('resources.in_hand_projects.actions.cancel_modal_heading'))
                        ->form([
                            Forms\Components\Select::make('reason')
                                ->label(__('resources.in_hand_projects.fields.lost_reason'))
                                ->options(LostReason::class)
                                ->required(),
                            Forms\Components\Textarea::make('note')
                                ->label(__('resources.in_hand_projects.fields.lost_reason_note'))
                                ->rows(3),
                            Forms\Components\TextInput::make('winning_competitor')
                                ->label(__('resources.in_hand_projects.fields.winning_competitor'))
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
                                    ->title(__('resources.in_hand_projects.notifications.moved_to_lost'))
                                    ->send();
                            } catch (DomainException $e) {
                                Notification::make()->danger()->title($e->getMessage())->send();
                            }
                        }),

                    Tables\Actions\Action::make('alarm')
                        ->label(__('resources.in_hand_projects.actions.set_alarm'))
                        ->icon('heroicon-o-bell-alert')
                        ->color('warning')
                        ->form([
                            Forms\Components\DateTimePicker::make('alarm_at')
                                ->label(__('resources.in_hand_projects.fields.alarm_at'))
                                ->required(),
                            Forms\Components\TextInput::make('alarm_note')
                                ->label(__('resources.in_hand_projects.fields.alarm_note'))
                                ->maxLength(255),
                        ])
                        ->visible(fn (Project $r) => auth()->user()?->can('setAlarm', $r) ?? false)
                        ->action(function (Project $r, array $data) {
                            app(SalesPipelineService::class)->setAlarm(
                                $r,
                                Carbon::parse($data['alarm_at']),
                                $data['alarm_note'] ?? null,
                            );
                            Notification::make()
                                ->success()
                                ->title(__('resources.in_hand_projects.notifications.alarm_set'))
                                ->send();
                        }),

                    Tables\Actions\Action::make('clear_alarm')
                        ->label(__('resources.in_hand_projects.actions.clear_alarm'))
                        ->icon('heroicon-o-bell-slash')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->visible(fn (Project $r) => $r->alarm_at !== null
                            && (auth()->user()?->can('setAlarm', $r) ?? false))
                        ->action(function (Project $r) {
                            app(SalesPipelineService::class)->clearAlarm($r);
                            Notification::make()
                                ->success()
                                ->title(__('resources.in_hand_projects.notifications.alarm_cleared'))
                                ->send();
                        }),
                ])
                    ->tooltip(__('resources.common.actions')),
            ]);
    }

    /**
     * Slide 11: persist the uploaded customer-acceptance / SMB file as a
     * project attachment. The upload already lands in the project's folder, so
     * we only record the row (idempotent on path).
     *
     * @param  string|array<int, string>|null  $file
     */
    protected static function storeAcceptanceFile(Project $project, string|array|null $file): void
    {
        $paths = array_filter((array) $file);
        if ($paths === []) {
            return;
        }

        $disk = Storage::disk('public');

        foreach ($paths as $path) {
            $project->attachments()->firstOrCreate(
                ['file_path' => $path],
                [
                    'file_name' => basename((string) $path),
                    'file_type' => $disk->mimeType($path) ?: null,
                    'file_size' => $disk->exists($path) ? $disk->size($path) : 0,
                    'category' => AttachmentCategory::CustomerAcceptance->value,
                    'uploaded_by' => auth()->id(),
                ],
            );
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInHandProjects::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('status', ProjectStatus::InHand);
    }
}
