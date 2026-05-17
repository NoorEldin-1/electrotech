<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PurchaseOrder extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'po_number',
        'supplier_name',
        'supplier_contact',
        'status',
        'total_amount',
        'notes',
        'expected_delivery_date',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'total_amount' => 'decimal:2',
            'expected_delivery_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['po_number', 'status', 'total_amount', 'approved_by', 'approved_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "PO #{$this->po_number} was {$eventName}");
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /**
     * Recalculate total_amount from line items.
     */
    public function recalculateTotal(): void
    {
        $this->total_amount = $this->items->sum(fn (PurchaseOrderItem $item) => (float) $item->quantity * (float) $item->unit_price);
        $this->saveQuietly();
    }

    /**
     * Generate a unique PO number with format: PO-YYYYMM-XXXX
     */
    public static function generatePoNumber(): string
    {
        $prefix = 'PO-' . now()->format('Ym') . '-';
        $lastPo = static::where('po_number', 'like', $prefix . '%')
            ->orderByDesc('po_number')
            ->first();

        $sequence = $lastPo
            ? ((int) substr($lastPo->po_number, -4)) + 1
            : 1;

        return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
