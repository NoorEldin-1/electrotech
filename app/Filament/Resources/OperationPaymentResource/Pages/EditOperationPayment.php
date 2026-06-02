<?php

declare(strict_types=1);

namespace App\Filament\Resources\OperationPaymentResource\Pages;

use App\Filament\Resources\OperationPaymentResource;
use Filament\Resources\Pages\EditRecord;

class EditOperationPayment extends EditRecord
{
    protected static string $resource = OperationPaymentResource::class;
}
