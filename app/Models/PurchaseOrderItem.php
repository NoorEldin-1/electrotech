<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PurchaseOrderItem extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'purchase_order_id',
        'item_id',
        'quantity',
        'unit_price',
        'received_quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'received_quantity' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['purchase_order_id', 'item_id', 'quantity', 'unit_price', 'received_quantity'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function getLineTotalAttribute(): float
    {
        return (float) $this->quantity * (float) $this->unit_price;
    }

    public function getRemainingQuantityAttribute(): float
    {
        return (float) $this->quantity - (float) $this->received_quantity;
    }

    public function isFullyReceived(): bool
    {
        return (float) $this->received_quantity >= (float) $this->quantity;
    }
}
