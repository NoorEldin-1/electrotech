<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Procurement;

use App\Enums\WarehouseType;
use App\Models\StockReservation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', StockReservation::class) ?? false;
    }

    /**
     * The availability check is deliberately absent. It lives in
     * InventoryService::holdStock, which reads the balance under a per-item
     * lock; checking it here would read outside that lock and let two
     * concurrent reservations both pass a test only one of them should.
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'warehouse' => ['nullable', Rule::enum(WarehouseType::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
