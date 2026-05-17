<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrderResource\Pages;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\PurchaseOrderService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Procurement';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'po_number';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Purchase Order Details')
                    ->icon('heroicon-o-shopping-cart')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('po_number')
                            ->label('PO Number')
                            ->default(fn () => PurchaseOrder::generatePoNumber())
                            ->disabled()
                            ->dehydrated()
                            ->required()
                            ->unique(ignoreRecord: true),

                        Forms\Components\Select::make('project_id')
                            ->relationship('project', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),

                        Forms\Components\TextInput::make('supplier_name')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('supplier_contact')
                            ->maxLength(255),

                        Forms\Components\Select::make('status')
                            ->options(PurchaseOrderStatus::class)
                            ->default(PurchaseOrderStatus::Draft)
                            ->required(),

                        Forms\Components\DatePicker::make('expected_delivery_date'),

                        Forms\Components\Hidden::make('created_by')
                            ->default(fn () => auth()->id()),

                        Forms\Components\Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Line Items')
                    ->icon('heroicon-o-list-bullet')
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

                                Forms\Components\TextInput::make('unit_price')
                                    ->label('Unit Price (EGP)')
                                    ->numeric()
                                    ->required()
                                    ->visible(fn () => auth()->user()?->can('inventory.view_pricing'))
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('received_quantity')
                                    ->numeric()
                                    ->default(0)
                                    ->disabled()
                                    ->dehydrated()
                                    ->columnSpan(1),
                            ])
                            ->defaultItems(1)
                            ->reorderable(false)
                            ->addActionLabel('Add Item'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('po_number')
                    ->label('PO #')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('project.name')
                    ->label('Project')
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('supplier_name')
                    ->label('Supplier')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_amount')
                    ->money('EGP')
                    ->sortable()
                    ->visible(fn () => auth()->user()?->can('inventory.view_pricing')),

                Tables\Columns\TextColumn::make('expected_delivery_date')
                    ->label('Expected Delivery')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(PurchaseOrderStatus::class)
                    ->multiple(),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('receive')
                    ->label('Receive Items')
                    ->icon('heroicon-o-truck')
                    ->color('success')
                    ->visible(fn (PurchaseOrder $record) => in_array($record->status, [
                        PurchaseOrderStatus::Submitted,
                        PurchaseOrderStatus::PartiallyReceived,
                    ]) && auth()->user()?->can('purchase_orders.receive'))
                    ->form(fn (PurchaseOrder $record) => $record->items->map(
                        fn (PurchaseOrderItem $poItem) => Forms\Components\TextInput::make("items.{$poItem->id}")
                            ->label("{$poItem->item->name} (Ordered: {$poItem->quantity}, Received: {$poItem->received_quantity})")
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->maxValue($poItem->remaining_quantity)
                    )->toArray())
                    ->action(function (PurchaseOrder $record, array $data) {
                        $receivedQuantities = [];
                        foreach ($data['items'] ?? [] as $poItemId => $qty) {
                            if ((float) $qty > 0) {
                                $receivedQuantities[(int) $poItemId] = (float) $qty;
                            }
                        }

                        if (empty($receivedQuantities)) {
                            Notification::make()
                                ->warning()
                                ->title('No quantities entered')
                                ->send();

                            return;
                        }

                        try {
                            app(PurchaseOrderService::class)->receiveItems($record, $receivedQuantities);
                            Notification::make()
                                ->success()
                                ->title('Items received successfully')
                                ->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()
                                ->danger()
                                ->title('Receiving failed')
                                ->body($e->getMessage())
                                ->send();
                        }
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
            'index' => Pages\ListPurchaseOrders::route('/'),
            'create' => Pages\CreatePurchaseOrder::route('/create'),
            'edit' => Pages\EditPurchaseOrder::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['project', 'items.item'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
