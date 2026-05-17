<?php
declare(strict_types=1);
namespace App\Filament\Resources\InventoryTransactionResource\Pages;
use App\Filament\Resources\InventoryTransactionResource;
use Filament\Resources\Pages\ListRecords;
class ListInventoryTransactions extends ListRecords
{
    protected static string $resource = InventoryTransactionResource::class;
}
