<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\ArrivalMethod;
use App\Enums\AttachmentCategory;
use App\Enums\ConductorType;
use App\Enums\ProjectStatus;
use App\Filament\Resources\ProjectResource\Pages;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\PhoneInput;
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

                        // Customer is now a first-class record: pick it from the
                        // customers list (with inline create) instead of typing a
                        // free-text name. The legacy `client_name` column is kept
                        // in sync server-side (see CreateProject/EditProject) so
                        // existing lists, PDFs and reports keep working.
                        Forms\Components\Select::make('customer_id')
                            ->label(__('resources.projects.fields.customer'))
                            ->relationship('customer', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->label(__('resources.customers.fields.name'))
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('contact_person')
                                    ->label(__('resources.customers.fields.contact_person'))
                                    ->maxLength(255),
                                PhoneInput::make('phone')
                                    ->label(__('resources.customers.fields.phone')),
                                Forms\Components\TextInput::make('email')
                                    ->label(__('resources.customers.fields.email'))
                                    ->email()
                                    ->maxLength(255),
                            ]),

                        Forms\Components\TextInput::make('consultant_name')
                            ->label(__('resources.projects.fields.consultant_name'))
                            ->maxLength(255),

                        Forms\Components\Select::make('status')
                            ->label(__('resources.projects.fields.status'))
                            ->options(ProjectStatus::class)
                            ->default(ProjectStatus::Tender)
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

                        Forms\Components\Select::make('section_type')
                            ->label(__('resources.projects.fields.section_type'))
                            ->options(ConductorType::class)
                            ->native(false),

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
                        MoneyInput::make('estimated_budget')
                            ->label(__('resources.projects.fields.estimated_budget'))
                            ->default(0),

                        MoneyInput::make('actual_cost')
                            ->label(__('resources.projects.fields.actual_cost'))
                            ->default(0)
                            ->disabled()
                            ->dehydrated(),

                        Forms\Components\DatePicker::make('start_date')
                            ->label(__('resources.projects.fields.start_date')),
                        Forms\Components\DatePicker::make('end_date')
                            ->label(__('resources.projects.fields.end_date'))
                            ->after('start_date')
                            ->helperText(__('resources.projects.fields.end_date_helper')),
                    ]),

                // Offers are now full BOQ documents (tables of priced line items
                // + VAT + terms), managed in the dedicated "Offers" tab once the
                // project exists. See OffersRelationManager.
                Forms\Components\Section::make(__('resources.projects.sections.offers'))
                    ->icon('heroicon-o-banknotes')
                    ->visibleOn('create')
                    ->schema([
                        Forms\Components\Placeholder::make('offers_hint')
                            ->hiddenLabel()
                            ->content(__('resources.projects.sections.offers_after_save_hint')),
                    ]),

                Forms\Components\Section::make(__('resources.projects.sections.attachments'))
                    ->icon('heroicon-o-paper-clip')
                    ->description(__('resources.projects.sections.attachments_description'))
                    ->columns(1)
                    ->schema(self::attachmentCategorySchema())
                    // Slide 3: other departments need to see/download the files
                    // Sales uploads — show the section to anyone who can download
                    // OR upload (the upload controls themselves are gated below).
                    ->visible(fn () => (auth()->user()?->can('attachments.download') ?? false)
                        || (auth()->user()?->can('attachments.upload') ?? false)),

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
            // Customer Documents are a customer-only bucket surfaced on the
            // Customer resource — they don't belong on the project form. Every
            // other category is shown here, including Customer Acceptance: the
            // consultant acceptance file (also uploaded by the In-Hand "move to
            // active" action) is stored as a project attachment under the same
            // category/directory, so it surfaces in — and can be managed from —
            // this section.
            //
            // PO / Addition-Voucher scans are uploaded directly on their own
            // records (not here): their upload fields were removed from the PO
            // and Addition Voucher forms too, so they're skipped on the project
            // form as well to keep the surfaces consistent. The enum cases stay
            // for any already-stored rows and the persistence layer.
            if (in_array($category, [
                AttachmentCategory::CustomerDocument,
                AttachmentCategory::PurchaseOrderScan,
                AttachmentCategory::AdditionVoucherScan,
            ], true)) {
                continue;
            }

            $components[] = Forms\Components\FileUpload::make("attachments_{$category->value}")
                ->label(__('resources.enums.attachment_category.'.$category->value))
                ->multiple()
                ->preserveFilenames()
                // Files are engineering deliverables (AutoCAD, BOQ sheets, and
                // crucially .rar/.zip bundles). Browsers can't preview an archive,
                // which is why "the file wouldn't open" (Slide 3). Keep the upload
                // unrestricted but expose explicit download/open controls and
                // disable the inline preview so every category is retrievable by
                // any department that can see the project.
                ->downloadable()
                ->openable()
                ->previewable(false)
                // Download-only departments see the files but can't add/remove
                // them (a disabled FileUpload still exposes download/open).
                ->disabled(fn (): bool => ! (auth()->user()?->can('attachments.upload') ?? false))
                ->maxSize(40960) // 40 MB — kept in sync with config/livewire.php temporary_file_upload rules
                ->directory(fn (?Project $record) => 'attachments/'.($record?->id ?? 'new').'/'.$category->value)
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
        return [
            ProjectResource\RelationManagers\OffersRelationManager::class,
            ProjectResource\RelationManagers\ActivitiesRelationManager::class,
        ];
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
