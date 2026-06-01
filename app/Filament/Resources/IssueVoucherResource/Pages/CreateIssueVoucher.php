<?php

declare(strict_types=1);

namespace App\Filament\Resources\IssueVoucherResource\Pages;

use App\Filament\Resources\IssueVoucherResource;
use App\Models\IssueVoucher;
use Filament\Resources\Pages\CreateRecord;

class CreateIssueVoucher extends CreateRecord
{
    protected static string $resource = IssueVoucherResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['voucher_number'] = IssueVoucher::generateVoucherNumber();
        $data['issued_by'] = auth()->id();

        return $data;
    }
}
