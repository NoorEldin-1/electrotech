<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QualitySheetStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ورقة الجودة — a structured manufacturing inspection sheet per work order
 * (التصنيع سلايد 2 سفلي + سلايد 3). Holds the operation header data plus the
 * test grid (lines). Filled and signed by QA, then approved by the factory
 * manager — the final approval alerts every department that the operation has
 * finished manufacturing. See App\Services\QualitySheetService.
 */
class QualitySheet extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'sheet_number',
        'work_order_id',
        'test_date',
        'conductor_type',
        'connection_type',
        'cross_section',
        'poles_count',
        'operation_name',
        'status',
        'qa_filled_by',
        'qa_filled_at',
        'qa_inspector_notes',
        'factory_approved_by',
        'factory_approved_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => QualitySheetStatus::class,
            'test_date' => 'date',
            'poles_count' => 'integer',
            'qa_filled_at' => 'datetime',
            'factory_approved_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['sheet_number', 'work_order_id', 'status', 'qa_filled_at', 'factory_approved_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Quality sheet {$this->sheet_number} was {$eventName}");
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QualitySheetLine::class)->orderBy('line_no');
    }

    public function qaFilledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qa_filled_by');
    }

    public function factoryApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'factory_approved_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isQaFilled(): bool
    {
        return $this->status === QualitySheetStatus::QaFilled;
    }

    public function isApproved(): bool
    {
        return $this->status === QualitySheetStatus::Approved;
    }

    /**
     * Generate a unique sheet number with format: QS-YYYYMM-XXXX.
     * See App\Models\ReturnVoucher::generateVoucherNumber() for the rationale
     * on the Cache lock pattern.
     */
    public static function generateSheetNumber(): string
    {
        $prefix = 'QS-' . now()->format('Ym') . '-';

        return Cache::lock('quality_sheet_seq:' . $prefix, 5)->block(3, function () use ($prefix) {
            $latest = static::query()
                ->withTrashed()
                ->where('sheet_number', 'like', $prefix . '%')
                ->orderByDesc('id')
                ->value('sheet_number');

            $seq = $latest ? (int) substr($latest, strlen($prefix)) : 0;

            return $prefix . str_pad((string) ($seq + 1), 4, '0', STR_PAD_LEFT);
        });
    }
}
