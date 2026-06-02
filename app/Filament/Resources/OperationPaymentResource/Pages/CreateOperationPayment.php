<?php

declare(strict_types=1);

namespace App\Filament\Resources\OperationPaymentResource\Pages;

use App\Filament\Resources\OperationPaymentResource;
use App\Models\OperationPayment;
use App\Services\OperationPaymentService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateOperationPayment extends CreateRecord
{
    protected static string $resource = OperationPaymentResource::class;

    /**
     * Route creation through the service so the GL bridge and claim
     * settlement fire instead of a plain insert.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(OperationPaymentService::class)->record($data);
    }
}
