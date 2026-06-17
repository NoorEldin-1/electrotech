<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
        'subtotal',
        'vat_amount',
        'profit_tax_amount',
        'apply_profit_tax',
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
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'profit_tax_amount' => 'decimal:2',
            'apply_profit_tax' => 'boolean',
            'total_amount' => 'decimal:2',
            'expected_delivery_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['po_number', 'status', 'subtotal', 'vat_amount', 'profit_tax_amount', 'total_amount', 'approved_by', 'approved_at'])
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
     * Scanned/printed PO documents (slide 6). Polymorphic — see
     * Attachment::attachable().
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Addition vouchers (إذن إضافة) that received goods against this order
     * (slide 7). Reviewing a voucher reveals its PO, and vice-versa.
     */
    public function additionVouchers(): HasMany
    {
        return $this->hasMany(AdditionVoucher::class);
    }

    /**
     * Slides 1 & 7: apply a posted addition voucher's received quantities to
     * the matching line items (capped at the ordered quantity), then let the
     * system re-derive the order's receipt status — the automatic comparison
     * between "ordered" and "received". Item lines are matched by item; with a
     * repeated item the remainder cascades to the next line with capacity.
     */
    public function applyReceivedFromVoucher(AdditionVoucher $voucher): void
    {
        $this->loadMissing('items');

        foreach ($voucher->lines as $line) {
            $remaining = (float) $line->quantity;

            foreach ($this->items as $poItem) {
                if ($remaining <= 0) {
                    break;
                }
                if ($poItem->item_id !== $line->item_id) {
                    continue;
                }

                $capacity = (float) $poItem->quantity - (float) $poItem->received_quantity;
                if ($capacity <= 0) {
                    continue;
                }

                $take = min($capacity, $remaining);
                $poItem->received_quantity = (float) $poItem->received_quantity + $take;
                $poItem->save();
                $remaining -= $take;
            }
        }

        $this->refreshReceiptStatus();
    }

    /**
     * Re-derive the receipt status from the line items: fully received,
     * partially received, or unchanged. Never overrides a cancelled order.
     */
    public function refreshReceiptStatus(): void
    {
        if ($this->status === PurchaseOrderStatus::Cancelled) {
            return;
        }

        $items = $this->items()->get();
        if ($items->isEmpty()) {
            return;
        }

        if ($items->every(fn (PurchaseOrderItem $item) => $item->isFullyReceived())) {
            $this->status = PurchaseOrderStatus::Received;
        } elseif ($items->contains(fn (PurchaseOrderItem $item) => (float) $item->received_quantity > 0)) {
            $this->status = PurchaseOrderStatus::PartiallyReceived;
        }

        $this->save();
    }

    /**
     * Recalculate the money breakdown from line items (slide 3):
     *   subtotal       = Σ(quantity × unit_price)
     *   vat_amount     = subtotal × VAT%        (added)
     *   profit_tax     = subtotal × profit_tax% (deducted, unless exempt)
     *   total_amount   = subtotal + vat − profit_tax
     *
     * The subtotal is summed at the database with a single aggregate query
     * instead of hydrating every PurchaseOrderItem into a model. For POs
     * with many line items this is ~10-100x faster and uses constant memory.
     */
    public function recalculateTotal(): void
    {
        $subtotal = (float) $this->items()
            ->selectRaw('COALESCE(SUM(quantity * unit_price), 0) AS aggregate')
            ->value('aggregate');

        $vatRate = (float) config('procurement.vat_percentage', 14);
        $profitRate = (float) config('procurement.profit_tax_percentage', 1);

        $vat = round($subtotal * $vatRate / 100, 2);
        $profitTax = $this->apply_profit_tax ? round($subtotal * $profitRate / 100, 2) : 0.0;

        $this->subtotal = $subtotal;
        $this->vat_amount = $vat;
        $this->profit_tax_amount = $profitTax;
        $this->total_amount = round($subtotal + $vat - $profitTax, 2);
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
