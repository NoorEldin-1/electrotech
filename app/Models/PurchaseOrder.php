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
        'supplier_id',
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
     *
     * Computes the sum at the database with a single aggregate query
     * instead of hydrating every PurchaseOrderItem into a model. For POs
     * with many line items this is ~10-100x faster and uses constant memory.
     */
    public function recalculateTotal(): void
    {
        $total = (float) $this->items()
            ->selectRaw('COALESCE(SUM(quantity * unit_price), 0) AS aggregate')
            ->value('aggregate');

        $this->total_amount = $total;
        $this->saveQuietly();
    }

    /**
     * Generate a unique PO number with format: PO-YYYYMM-XXXX
     *
     * See App\Models\Project::generateCode() for the rationale on the
     * MAX(CAST(...)) aggregate + Redis lock pattern.
     */
    public static function generatePoNumber(): string
    {
        $prefix = 'PO-' . now()->format('Ym') . '-';

        return \Illuminate\Support\Facades\Cache::lock('po_number_seq:' . $prefix, 5)->block(3, function () use ($prefix) {
            $maxSequence = (int) static::query()
                ->where('po_number', 'like', $prefix . '%')
                ->selectRaw('COALESCE(MAX(CAST(SUBSTRING_INDEX(po_number, "-", -1) AS UNSIGNED)), 0) AS seq')
                ->value('seq');

            return $prefix . str_pad((string) ($maxSequence + 1), 4, '0', STR_PAD_LEFT);
        });
    }
}
