<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Manufacturing;

use App\Http\Resources\Api\V1\Manufacturing\QualitySheetResource;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Write the test grid of a quality sheet.
 *
 * The payload mirrors the read shape exactly — `checks` for the three pass/
 * fail marks, `tests` for the five electrical tests with two readings each —
 * so a client can round-trip a sheet without a translation layer. The column
 * names come from QualitySheetResource::TEST_COLUMNS, so the read and write
 * shapes cannot drift apart.
 *
 * Readings are STRINGS, not numbers. The sheet records what the meter showed,
 * and the paper form it replaces legitimately carries entries like "OK" or a
 * dash. Forcing them numeric would make a sheet that is valid on paper
 * impossible to file.
 */
class ReplaceQualitySheetLinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('quality_sheet')) ?? false;
    }

    public function rules(): array
    {
        $rules = [
            'lines' => ['present', 'array', 'max:200'],
            'lines.*.line_no' => ['nullable', 'integer', 'min:1'],
            'lines.*.label' => ['nullable', 'string', 'max:255'],
            'lines.*.piece_number' => ['nullable', 'string', 'max:255'],
            'lines.*.required_size' => ['nullable', 'string', 'max:255'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],

            'lines.*.checks' => ['nullable', 'array'],
            'lines.*.checks.visual_quality' => ['nullable', 'boolean'],
            'lines.*.checks.assembly' => ['nullable', 'boolean'],
            'lines.*.checks.earth_bond_pe_fe' => ['nullable', 'boolean'],

            'lines.*.tests' => ['nullable', 'array'],
        ];

        foreach (array_keys(QualitySheetResource::TEST_COLUMNS) as $test) {
            $rules["lines.*.tests.{$test}"] = ['nullable', 'array'];
            $rules["lines.*.tests.{$test}.r1"] = ['nullable', 'string', 'max:100'];
            $rules["lines.*.tests.{$test}.r2"] = ['nullable', 'string', 'max:100'];
        }

        return $rules;
    }
}
