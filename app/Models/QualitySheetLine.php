<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single test row (بند) on a quality sheet — one inspected piece with its
 * visual check, required size, and the insulation / continuity test results.
 */
class QualitySheetLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'quality_sheet_id',
        'line_no',
        'label',
        'piece_number',
        'assembly',
        'visual_quality',
        'required_size',
        'test_pe_l123n',
        'test_fe_l123n',
        'test_n_l12l3',
        'test_l1_l2l3',
        'test_l2_l3',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
        ];
    }

    public function qualitySheet(): BelongsTo
    {
        return $this->belongsTo(QualitySheet::class);
    }
}
