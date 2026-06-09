<?php

namespace Tests\Feature\Sales;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slide 4: the operation code must be year-based ("2026-1"), not month-based
 * ("PRJ-202606-0001"), and not zero-padded.
 */
class CodeFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_code_of_the_year_is_year_dash_one(): void
    {
        $year = now()->format('Y');

        $this->assertSame("{$year}-1", Project::generateCode());
    }

    public function test_sequence_increments_within_the_year(): void
    {
        $year = now()->format('Y');
        Project::factory()->create(['code' => "{$year}-1"]);
        Project::factory()->create(['code' => "{$year}-2"]);

        $this->assertSame("{$year}-3", Project::generateCode());
    }

    public function test_code_has_a_single_dash_and_no_padding(): void
    {
        $code = Project::generateCode();

        $this->assertSame(1, substr_count($code, '-'), 'Code must contain the year and sequence only (no month segment).');
        $this->assertMatchesRegularExpression('/^\d{4}-[1-9]\d*$/', $code);
    }

    public function test_legacy_prj_codes_do_not_affect_the_year_sequence(): void
    {
        $year = now()->format('Y');
        Project::factory()->create(['code' => 'PRJ-202605-0009']);

        $this->assertSame("{$year}-1", Project::generateCode());
    }
}
