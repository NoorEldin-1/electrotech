<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\InteractionChannel;
use App\Enums\InteractionType;
use App\Filament\Resources\CustomerInteractionResource\Pages;
use App\Models\CustomerInteraction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * متابعة العملاء — قسم إدارة العملاء (Presentation.pptx سلايد 6–7). The log of
 * touchpoints with the customer per operation; "التواصل مع العميل" and
 * "جاهزية الموقع" on the operation timeline are read from here.
 */
class CustomerInteractionResource extends Resource
{
    protected static ?string $model = CustomerInteraction::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?int $navigationSort = 7;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.sales_crm');
    }

    public static function getLabel(): string
    {
        return __('resources.customer_interactions.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.customer_interactions.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.customer_interactions.navigation_label');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['project:id,name,code', 'customer:id,name', 'createdBy:id,name']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('resources.customer_interactions.sections.details'))
                ->columns(2)
                ->schema(self::formFields(withProject: true)),
        ]);
    }

    /**
     * Shared with the project's relation manager, which fixes the operation
     * itself and so drops the project picker.
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function formFields(bool $withProject): array
    {
        return array_values(array_filter([
            $withProject
                ? Forms\Components\Select::make('project_id')
                    ->label(__('resources.customer_interactions.fields.operation'))
                    ->relationship('project', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record): string => "{$record->code} — {$record->name}")
                    ->searchable(['name', 'code'])
                    ->preload()
                    ->required()
                : null,

            Forms\Components\Select::make('type')
                ->label(__('resources.customer_interactions.fields.type'))
                ->options(InteractionType::class)
                ->default(InteractionType::Contact)
                ->live()
                ->required(),

            Forms\Components\Select::make('channel')
                ->label(__('resources.customer_interactions.fields.channel'))
                ->options(InteractionChannel::class)
                ->nullable(),

            Forms\Components\DateTimePicker::make('interacted_at')
                ->label(__('resources.customer_interactions.fields.interacted_at'))
                ->default(now())
                ->seconds(false)
                ->required(),

            Forms\Components\Toggle::make('site_ready')
                ->label(__('resources.customer_interactions.fields.site_ready'))
                ->helperText(__('resources.customer_interactions.fields.site_ready_hint'))
                ->visible(fn (Get $get): bool => self::isSiteReadiness($get('type')))
                ->inline(false),

            Forms\Components\Textarea::make('notes')
                ->label(__('resources.customer_interactions.fields.notes'))
                ->rows(3)
                ->columnSpanFull(),
        ]));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(self::tableColumns(withProject: true))
            ->defaultSort('interacted_at', 'desc')
            ->filters(self::tableFilters(withProject: true))
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ])
                    ->tooltip(__('resources.common.actions')),
            ]);
    }

    /**
     * @return array<int, Tables\Columns\Column>
     */
    public static function tableColumns(bool $withProject): array
    {
        return array_values(array_filter([
            Tables\Columns\TextColumn::make('interacted_at')
                ->label(__('resources.customer_interactions.columns.interacted_at'))
                ->dateTime('Y-m-d H:i')
                ->sortable(),
            $withProject
                ? Tables\Columns\TextColumn::make('project.name')
                    ->label(__('resources.customer_interactions.columns.operation'))
                    ->description(fn (CustomerInteraction $record): ?string => $record->project?->code)
                    ->searchable(['name', 'code'])
                    ->limit(30)
                : null,
            $withProject
                ? Tables\Columns\TextColumn::make('customer.name')
                    ->label(__('resources.customer_interactions.columns.customer'))
                    ->placeholder(__('resources.common.no_data'))
                    ->toggleable()
                : null,
            Tables\Columns\TextColumn::make('type')
                ->label(__('resources.customer_interactions.columns.type'))
                ->badge(),
            Tables\Columns\TextColumn::make('channel')
                ->label(__('resources.customer_interactions.columns.channel'))
                ->placeholder(__('resources.common.no_data')),
            Tables\Columns\IconColumn::make('site_ready')
                ->label(__('resources.customer_interactions.columns.site_ready'))
                ->boolean(),
            Tables\Columns\TextColumn::make('notes')
                ->label(__('resources.customer_interactions.columns.notes'))
                ->limit(50)
                ->wrap()
                ->placeholder(__('resources.common.no_data')),
            Tables\Columns\TextColumn::make('createdBy.name')
                ->label(__('resources.customer_interactions.columns.created_by'))
                ->placeholder(__('resources.common.no_data'))
                ->toggleable(isToggledHiddenByDefault: true),
        ]));
    }

    /**
     * @return array<int, Tables\Filters\BaseFilter>
     */
    public static function tableFilters(bool $withProject): array
    {
        return array_values(array_filter([
            Tables\Filters\SelectFilter::make('type')
                ->label(__('resources.customer_interactions.fields.type'))
                ->options(InteractionType::class),
            $withProject
                ? Tables\Filters\SelectFilter::make('project_id')
                    ->label(__('resources.customer_interactions.fields.operation'))
                    ->relationship('project', 'name')
                    ->searchable()
                    ->preload()
                : null,
            $withProject
                ? Tables\Filters\SelectFilter::make('customer_id')
                    ->label(__('resources.customer_interactions.columns.customer'))
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload()
                : null,
        ]));
    }

    private static function isSiteReadiness(mixed $type): bool
    {
        return ($type instanceof InteractionType ? $type : InteractionType::tryFrom((string) $type)) === InteractionType::SiteReadiness;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomerInteractions::route('/'),
            'create' => Pages\CreateCustomerInteraction::route('/create'),
            'edit' => Pages\EditCustomerInteraction::route('/{record}/edit'),
        ];
    }
}
