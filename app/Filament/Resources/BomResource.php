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

    protected static ?string $navigationGroup = 'Technical Office';

    protected static ?int $navigationSort = 2;

    protected static ?string $label = 'Bill of Materials';

    protected static ?string $pluralLabel = 'Bills of Materials';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('BOM Details')
                    ->icon('heroicon-o-document-text')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('project_id')
                            ->relationship('project', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),

                        Forms\Components\TextInput::make('version')
                            ->numeric()
                            ->default(1)
                            ->required(),

                        Forms\Components\Select::make('status')
                            ->options(BomStatus::class)
                            ->default(BomStatus::Draft)
                            ->required(),

                        Forms\Components\Hidden::make('prepared_by')
                            ->default(fn () => auth()->id()),

                        Forms\Components\Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('BOM Items')
                    ->icon('heroicon-o-list-bullet')
                    ->description('Add items required for this project. Include waste percentage as per technical specifications.')
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->relationship()
                            ->columns(4)
                            ->schema([
                                Forms\Components\Select::make('item_id')
                                    ->relationship('item', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('quantity')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0.0001)
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('waste_percentage')
                                    ->label('Waste %')
                                    ->numeric()
                                    ->default(0)
                                    ->suffix('%')
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('notes')
                                    ->maxLength(255)
                                    ->columnSpan(1),
                            ])
                            ->defaultItems(1)
                            ->reorderable(false)
                            ->addActionLabel('Add BOM Item'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('project.name')
                    ->label('Project')
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('version')
                    ->label('Version')
                    ->prefix('v')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items')
                    ->sortable(),

                Tables\Columns\TextColumn::make('preparedBy.name')
                    ->label('Prepared By')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('approvedBy.name')
                    ->label('Approved By')
                    ->toggleable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(BomStatus::class)
                    ->multiple(),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('approve')
                    ->label('Approve')
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
                        Notification::make()->success()->title('BOM approved successfully')->send();
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
            ->with(['project', 'preparedBy', 'approvedBy'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
