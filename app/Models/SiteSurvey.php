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
 * معاينة موقع — the engineering site visit for an operation (سلايد 1: مقاسات
 * الموقع والرسومات).
 */
class SiteSurvey extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'survey_date',
        'measurements',
        'surveyed_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'survey_date' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['project_id', 'survey_date', 'surveyed_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function surveyedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'surveyed_by');
    }
}
