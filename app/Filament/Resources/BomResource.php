<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\BomStatus;
use App\Filament\Resources\BomResource\Pages;
use App\Models\Bom;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class BomResource extends Resource
{
    protected static ?string $model = Bom::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 21;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.technical_office');
    }

    public static function getLabel(): string
    {
        return __('resources.boms.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.boms.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.boms.navigation_label');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('resources.boms.sections.bom_details'))
                    ->icon('heroicon-o-document-text')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('project_id')
                            ->label(__('resources.boms.fields.project'))
                            ->relationship('project', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),

                        Forms\Components\TextInput::make('version')
                            ->label(__('resources.boms.fields.version'))
                            ->numeric()
                            ->default(1)
                            ->required(),

                        Forms\Components\Select::make('status')
                            ->label(__('resources.boms.fields.status'))
                            ->options(BomStatus::class)
                            ->default(BomStatus::Draft)
                            ->required(),

                        Forms\Components\Hidden::make('prepared_by')
                            ->default(fn () => auth()->id()),

                        Forms\Components\Textarea::make('notes')
                            ->label(__('resources.boms.fields.notes'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make(__('resources.boms.sections.bom_items'))
                    ->icon('heroicon-o-list-bullet')
                    ->description(__('resources.boms.sections.bom_items_description'))
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->relationship()
                            ->columns(4)
                            ->schema([
                                Forms\Components\Select::make('item_id')
                                    ->label(__('resources.boms.fields.item'))
                                    ->relationship('item', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    // ->live() so the quick-view suffix action
                                    // re-renders (becomes visible) once an item
                                    // is picked.
                                    ->live()
                                    ->suffixAction(ItemResource::quickViewAction())
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('quantity')
                                    ->label(__('resources.boms.fields.quantity'))
                                    ->numeric()
                                    ->required()
                                    ->minValue(0.0001)
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('waste_percentage')
                                    ->label(__('resources.boms.fields.waste_percentage'))
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->suffix('%')
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('notes')
                                    ->label(__('resources.boms.fields.notes'))
                                    ->maxLength(255)
                                    ->columnSpan(1),
                            ])
                            ->defaultItems(1)
                            ->reorderable(false)
                            ->addActionLabel(__('resources.boms.actions.add_bom_item')),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('project.name')
                    ->label(__('resources.boms.columns.project'))
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('project.status')
                    ->label(__('resources.boms.columns.sales_stage'))
                    ->badge()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('version')
                    ->label(__('resources.boms.columns.version'))
                    ->prefix('v')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('resources.boms.columns.status'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('items_count')
                    ->label(__('resources.boms.columns.items_count'))
                    ->counts('items')
                    ->sortable(),

                Tables\Columns\TextColumn::make('preparedBy.name')
                    ->label(__('resources.boms.columns.prepared_by'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('approvedBy.name')
                    ->label(__('resources.boms.columns.approved_by'))
                    ->toggleable()
                    ->placeholder(__('resources.common.no_data')),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('resources.boms.columns.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('resources.boms.columns.status'))
                    ->options(BomStatus::class)
                    ->multiple(),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('approve')
                    ->label(__('resources.boms.actions.approve'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Bom $record) => $record->status === BomStatus::PendingApproval
                        && auth()->user()?->can('boms.approve'))
                    ->action(function (Bom $record) {
                        $record->update([
                            'status' => BomStatus::Approved,
                            'approved_by' => Auth::id(),
                            'approved_at' => now(),
                        ]);
                        Notification::make()->success()->title(__('resources.boms.notifications.approved'))->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBoms::route('/'),
            'create' => Pages\CreateBom::route('/create'),
            'edit' => Pages\EditBom::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'project:id,name',
                'preparedBy:id,name',
                'approvedBy:id,name',
            ])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
