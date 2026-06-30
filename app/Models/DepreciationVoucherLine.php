<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepreciationVoucherLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'depreciation_voucher_id',
        'item_id',
        'quantity',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:2',
        ];
    }

    public function depreciationVoucher(): BelongsTo
    {
        return $this->belongsTo(DepreciationVoucher::class);
    }

    /**
     * The item whose loss is being written off (taken out of WIP).
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
