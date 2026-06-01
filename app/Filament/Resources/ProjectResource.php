<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\ArrivalMethod;
use App\Enums\AttachmentCategory;
use App\Enums\ProjectStatus;
use App\Filament\Resources\ProjectResource\Pages;
use App\Models\Project;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static ?string $navigationIcon = 'heroicon-o-briefcase';

    protected static ?int $navigationSort = 10;

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
                            ->disabled()
                            ->dehydrated()
                            ->helperText(__('resources.projects.fields.status_helper'))
                            ->required(),

                        Forms\Components\Hidden::make('created_by')
                            ->default(fn () => auth()->id()),
                    ]),

                Forms\Components\Section::make(__('resources.projects.sections.technical_specifications'))
                    ->icon('heroicon-o-cog-6-tooth')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('engineer_name')
                            ->label(__('resources.projects.fields.engineer_name'))
                            ->maxLength(255),

                        Forms\Components\TextInput::make('electric_current')
                            ->label(__('resources.projects.fields.electric_current'))
                            ->maxLength(255)
                            ->placeholder('e.g. 63A'),

                        Forms\Components\TextInput::make('model')
                            ->label(__('resources.projects.fields.model'))
                            ->maxLength(255),

                        Forms\Components\TextInput::make('section_type')
                            ->label(__('resources.projects.fields.section_type'))
                            ->maxLength(255),

                        Forms\Components\TextInput::make('poles_count')
                            ->label(__('resources.projects.fields.poles_count'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(99),

                        Forms\Components\TextInput::make('quantity')
                            ->label(__('resources.projects.fields.quantity'))
                            ->numeric()
                            ->minValue(1),

                        Forms\Components\TextInput::make('project_location')
                            ->label(__('resources.projects.fields.project_location'))
                            ->maxLength(255),

                        Forms\Components\Select::make('arrival_method')
                            ->label(__('resources.projects.fields.arrival_method'))
                            ->options(ArrivalMethod::class),
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

                Forms\Components\Section::make(__('resources.projects.sections.offers'))
                    ->icon('heroicon-o-banknotes')
                    ->description(__('resources.projects.sections.offers_description'))
                    ->schema([
                        Forms\Components\Placeholder::make('latest_offer_summary')
                            ->label(__('resources.projects.fields.latest_offer'))
                            ->content(function (?Project $record): string {
                                $offer = $record?->latestOffer;
                                if ($offer === null) {
                                    return __('resources.projects.fields.no_offers_yet');
                                }
                                $financial = number_format((float) $offer->financial_amount, 2);
                                $technical = $offer->technical_amount !== null
                                    ? ' / ' . number_format((float) $offer->technical_amount, 2)
                                    : '';

                                return "v{$offer->version} — EGP {$financial}{$technical}";
                            }),

                        Forms\Components\Repeater::make('offers')
                            ->relationship()
                            ->label(__('resources.projects.sections.offers'))
                            ->columns(4)
                            ->schema([
                                Forms\Components\TextInput::make('financial_amount')
                                    ->label(__('resources.projects.fields.financial_amount'))
                                    ->numeric()
                                    ->prefix('EGP')
                                    ->required(),

                                Forms\Components\TextInput::make('technical_amount')
                                    ->label(__('resources.projects.fields.technical_amount'))
                                    ->numeric()
                                    ->prefix('EGP'),

                                Forms\Components\DateTimePicker::make('submitted_at')
                                    ->label(__('resources.projects.fields.submitted_at'))
                                    ->default(now())
                                    ->required(),

                                Forms\Components\TextInput::make('notes')
                                    ->label(__('resources.projects.fields.offer_notes'))
                                    ->maxLength(255),

                                Forms\Components\Hidden::make('submitted_by')
                                    ->default(fn () => auth()->id()),
                            ])
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->visible(fn () => auth()->user()?->can('project_offers.view') ?? false),
                    ]),

                Forms\Components\Section::make(__('resources.projects.sections.attachments'))
                    ->icon('heroicon-o-paper-clip')
                    ->description(__('resources.projects.sections.attachments_description'))
                    ->columns(1)
                    ->schema(self::attachmentCategorySchema())
                    ->visible(fn () => auth()->user()?->can('attachments.upload') ?? false),

                Forms\Components\Section::make(__('resources.projects.sections.description'))
                    ->schema([
                        Forms\Components\Textarea::make('description')
                            ->label(__('resources.projects.fields.description'))
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * One FileUpload per AttachmentCategory. Files are persisted as
     * Attachment rows through the saveRelationshipsUsing pipeline of
     * the page's afterSave hook (see CreateProject / EditProject).
     */
    protected static function attachmentCategorySchema(): array
    {
        $components = [];
        foreach (AttachmentCategory::cases() as $category) {
            $components[] = Forms\Components\FileUpload::make("attachments_{$category->value}")
                ->label(__('resources.enums.attachment_category.' . $category->value))
                ->multiple()
                ->preserveFilenames()
                ->directory(fn (?Project $record) => 'attachments/' . ($record?->id ?? 'new') . '/' . $category->value)
                ->disk('public')
                ->afterStateHydrated(function (Forms\Components\FileUpload $component, ?Project $record) use ($category) {
                    if ($record === null) {
                        return;
                    }
                    $component->state(
                        $record->attachmentsByCategory($category)
                            ->pluck('file_path')
                            ->all()
                    );
                });
        }

        return $components;
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
                // Single status-change action. Bypasses the SalesPipelineService
                // state machine — gated by the `projects.change_status`
                // permission and recorded in the activity log via the
                // Project model's LogsActivity trait.
                Tables\Actions\Action::make('change_status')
                    ->label(__('resources.projects.actions.change_status'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->modalHeading(__('resources.projects.actions.change_status_modal_heading'))
                    ->modalDescription(__('resources.projects.actions.change_status_modal_description'))
                    ->form(fn (Project $r) => [
                        Forms\Components\Select::make('status')
                            ->label(__('resources.projects.fields.status'))
                            ->options(ProjectStatus::class)
                            ->default($r->status?->value)
                            ->required(),
                    ])
                    ->visible(fn () => auth()->user()?->can('projects.change_status') ?? false)
                    ->action(function (Project $r, array $data) {
                        $r->status = ProjectStatus::from($data['status']);
                        $r->save();

                        Notification::make()
                            ->success()
                            ->title(__('resources.projects.notifications.status_changed'))
                            ->send();
                    }),

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
