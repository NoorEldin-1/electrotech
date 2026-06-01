<?php

declare(strict_types=1);

namespace App\Filament\Resources\IssueVoucherResource\Pages;

use App\Filament\Resources\IssueVoucherResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListIssueVouchers extends ListRecords
{
    protected static string $resource = IssueVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
