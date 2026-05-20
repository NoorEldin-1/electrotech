<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Sync\Concerns\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Project extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;
    use Syncable;

    /**
     * Projects are read-only on the factory floor; operators must not
     * mutate budgets, dates, or status from offline. Empty array =
     * sync push for this model is rejected entirely.
     */
    public function syncWritableFields(): array
    {
        return [];
    }

    protected $fillable = [
        'name',
        'code',
        'client_name',
        'consultant_name',
        'description',
        'status',
        'estimated_budget',
        'actual_cost',
        'start_date',
        'end_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'estimated_budget' => 'decimal:2',
            'actual_cost' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'status', 'estimated_budget', 'actual_cost'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Project '{$this->name}' was {$eventName}");
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function boms(): HasMany
    {
        return $this->hasMany(Bom::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * Generate a unique project code with format: PRJ-YYYYMM-XXXX
     *
     * Pulls just the max sequence for the current month with a single
     * aggregate query (no model hydration, no full row fetch). Wrapped in
     * a Redis lock to make the generate→insert sequence race-safe under
     * concurrent creates.
     */
    public static function generateCode(): string
    {
        $prefix = 'PRJ-' . now()->format('Ym') . '-';

        return \Illuminate\Support\Facades\Cache::lock('project_code_seq:' . $prefix, 5)->block(3, function () use ($prefix) {
            $maxSequence = (int) static::query()
                ->where('code', 'like', $prefix . '%')
                ->selectRaw('COALESCE(MAX(CAST(SUBSTRING_INDEX(code, "-", -1) AS UNSIGNED)), 0) AS seq')
                ->value('seq');

            return $prefix . str_pad((string) ($maxSequence + 1), 4, '0', STR_PAD_LEFT);
        });
    }
}
