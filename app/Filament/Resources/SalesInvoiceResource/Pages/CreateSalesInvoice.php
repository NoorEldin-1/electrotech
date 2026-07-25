<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesInvoiceResource\Pages;

use App\Filament\Resources\SalesInvoiceResource;
use App\Models\DeliveryVoucher;
use App\Services\SalesInvoicingService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSalesInvoice extends CreateRecord
{
    protected static string $resource = SalesInvoiceResource::class;

    /**
     * Route creation through the service so the voucher guards (active only,
     * no over-invoicing) and the status recalculation always run.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $voucher = DeliveryVoucher::findOrFail($data['delivery_voucher_id']);

        return app(SalesInvoicingService::class)->record($voucher, $data, auth()->user());
    }
}
