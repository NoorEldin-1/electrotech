<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
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
        'quotation_number',
        'currency',
        'financial_amount',
        'technical_amount',
        'vat_percentage',
        'show_vat',
        'subtotal',
        'tax_amount',
        'grand_total',
        'submitted_at',
        'submitted_by',
        'notes',
        'terms',
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

            // The Offers repeater on the Project form can't reliably seed a
            // Hidden submitted_by/submitted_at for a row created in the
            // browser (it submitted NULL and hit the NOT NULL constraint),
            // so default them here — same belt-and-suspenders as `version`.
            if (empty($offer->submitted_by)) {
                $offer->submitted_by = Auth::id();
            }

            if (empty($offer->submitted_at)) {
                $offer->submitted_at = now();
            }

            // financial_amount is NOT NULL but the BOQ form no longer asks for
            // it directly — it is derived from the line items by
            // OfferTotalsService after the offer (and its groups/items) save.
            // Seed a 0 so the initial insert satisfies the constraint.
            if ($offer->financial_amount === null) {
                $offer->financial_amount = 0;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'financial_amount' => 'decimal:2',
            'technical_amount' => 'decimal:2',
            'vat_percentage' => 'decimal:2',
            'show_vat' => 'boolean',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'submitted_at' => 'datetime',
            'is_winning' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['version', 'financial_amount', 'technical_amount', 'grand_total', 'vat_percentage', 'is_winning'])
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

    public function groups(): HasMany
    {
        return $this->hasMany(OfferGroup::class)->orderBy('sort_order');
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
