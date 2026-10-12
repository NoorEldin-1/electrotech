<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InteractionChannel;
use App\Enums\InteractionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * متابعة العملاء — one touchpoint with the customer on an operation
 * (Presentation.pptx سلايد 6–7: «يتم اضافته من خلال قسم ادارة العملاء»).
 */
class CustomerInteraction extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'customer_id',
        'type',
        'channel',
        'interacted_at',
        'site_ready',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => InteractionType::class,
            'channel' => InteractionChannel::class,
            'interacted_at' => 'datetime',
            'site_ready' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // The customer is the operation's customer — filled here so neither
        // the form nor the API has to send (or can contradict) it.
        static::saving(function (CustomerInteraction $interaction): void {
            if ($interaction->isDirty('project_id') || $interaction->customer_id === null) {
                $interaction->customer_id = $interaction->project?->customer_id;
            }

            // "Site ready" only means something on a site-readiness entry.
            if ($interaction->type !== InteractionType::SiteReadiness) {
                $interaction->site_ready = false;
            }
        });

        static::creating(function (CustomerInteraction $interaction): void {
            $interaction->created_by ??= auth()->id();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['project_id', 'type', 'channel', 'interacted_at', 'site_ready'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
