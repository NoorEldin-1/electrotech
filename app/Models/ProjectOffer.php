<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One financial+technical offer submitted by Sales for a project.
 * Multiple offers per project let us answer Slide 4's "compare with
 * last offer" question and capture which competitor's price won when
 * the project goes to Lost.
 */
class ProjectOffer extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'version',
        'financial_amount',
        'technical_amount',
        'submitted_at',
        'submitted_by',
        'notes',
        'is_winning',
    ];

    /**
     * Auto-assign `version` at insert time, NOT at form-render time.
     *
     * Filament's Repeater builds the form with a single default version
     * for every new row, so submitting 2+ offers in one save would give
     * them the same version and hit the (project_id, version) unique
     * index. Computing it inside `creating` means each row is inserted
     * sequentially and the second one sees the first already in the DB.
     */
    protected static function booted(): void
    {
        static::creating(function (ProjectOffer $offer): void {
            if (empty($offer->version) && ! empty($offer->project_id)) {
                $offer->version = static::nextVersionFor((int) $offer->project_id);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'financial_amount' => 'decimal:2',
            'technical_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'is_winning' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['version', 'financial_amount', 'technical_amount', 'is_winning'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(
                fn (string $eventName) => "Offer v{$this->version} for project #{$this->project_id} was {$eventName}"
            );
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public static function nextVersionFor(int $projectId): int
    {
        return ((int) static::query()
            ->where('project_id', $projectId)
            ->max('version')) + 1;
    }
}
