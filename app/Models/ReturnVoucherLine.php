<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnVoucherLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_voucher_id',
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

    public function returnVoucher(): BelongsTo
    {
        return $this->belongsTo(ReturnVoucher::class);
    }

    /**
     * The original raw item being returned (scrap routes into its variant).
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
