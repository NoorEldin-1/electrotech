<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Inventory;

use App\Models\DepreciationVoucher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDepreciationVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', DepreciationVoucher::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'work_order_id' => ['required', 'integer', Rule::exists('work_orders', 'id')->whereNull('deleted_at')],
        ];
    }
}
