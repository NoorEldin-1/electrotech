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
        // علامات صح (سلايد 4).
        'visual_quality',
        'assembly',
        'earth_bond_pe_fe',
        'required_size',
        // خانات الاختبار الكهربى — قراءتان لكل خانة.
        'test_pe_l123n_r1',
        'test_pe_l123n_r2',
        'test_fe_l123n_r1',
        'test_fe_l123n_r2',
        'test_n_l12l3_r1',
        'test_n_l12l3_r2',
        'test_l1_l2l3_r1',
        'test_l1_l2l3_r2',
        'test_l2_l3_r1',
        'test_l2_l3_r2',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'visual_quality' => 'boolean',
            'assembly' => 'boolean',
            'earth_bond_pe_fe' => 'boolean',
        ];
    }

    public function qualitySheet(): BelongsTo
    {
        return $this->belongsTo(QualitySheet::class);
    }
}
