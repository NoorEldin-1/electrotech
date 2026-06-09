<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single priced line of a BOQ offer table (Slide 7): description / unit /
 * quantity / unit price. line_total is derived (qty × unit price) and stored
 * so totals can be summed without re-deriving on every read.
 */
class OfferItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'offer_group_id',
        'description',
        'unit',
        'quantity',
        'unit_price',
        'line_total',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (OfferItem $item): void {
            $item->line_total = round((float) $item->quantity * (float) $item->unit_price, 2);
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(OfferGroup::class, 'offer_group_id');
    }
}
