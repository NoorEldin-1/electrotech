<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Models\QualitySheet;
use App\Models\QualitySheetLine;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\QualitySheetService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ورقة الجودة — بيانات العملية + بنود الاختبار (سلايد 2–4). Header specs are
 * snapshot-copied from the manufacturing order; test lines carry checkboxes and
 * two readings per electrical test.
 */
class QualitySheetSpecsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_ensure_for_work_order_snapshots_the_specs_from_the_order(): void
    {
        $wo = WorkOrder::factory()->create([
            'conductor_type' => 'Copper',
            'cross_section' => '25',
            'cross_section_e' => '16',
            'external_body' => 'Steel',
            'protection_degree' => 'IP54',
            'paint' => 'RAL7035',
            'model' => 'DKC-PT',
            'ampere' => '3000',
            'poles_count' => 4,
        ]);

        $sheet = app(QualitySheetService::class)->ensureForWorkOrder($wo);

        $this->assertSame('Copper', $sheet->conductor_type);
        $this->assertSame('25', $sheet->cross_section);
        $this->assertSame('16', $sheet->cross_section_e);
        $this->assertSame('Steel', $sheet->external_body);
        $this->assertSame('IP54', $sheet->protection_degree);
        $this->assertSame('RAL7035', $sheet->paint);
        $this->assertSame('DKC-PT', $sheet->model);
        $this->assertSame('3000', $sheet->ampere);
        $this->assertSame(4, $sheet->poles_count);
    }

    public function test_editing_the_sheet_specs_does_not_change_the_work_order(): void
    {
        $wo = WorkOrder::factory()->create(['ampere' => '3000']);
        $sheet = app(QualitySheetService::class)->ensureForWorkOrder($wo);

        $sheet->update(['ampere' => '3200']);

        // The snapshot is independent — the order keeps its authored value.
        $this->assertSame('3000', $wo->fresh()->ampere);
        $this->assertSame('3200', $sheet->fresh()->ampere);
    }

    public function test_spec_snapshot_falls_back_to_title_for_operation_name(): void
    {
        // No project linked → operation_name falls back to the title.
        $wo = WorkOrder::factory()->make(['project_id' => null, 'title' => 'Bus Bar 3000A']);

        $snapshot = QualitySheetService::specSnapshotFrom($wo);

        $this->assertSame('Bus Bar 3000A', $snapshot['operation_name']);
    }

    public function test_line_stores_checkbox_booleans_and_dual_readings(): void
    {
        $sheet = QualitySheet::factory()->create();

        $line = QualitySheetLine::create([
            'quality_sheet_id' => $sheet->id,
            'line_no' => 1,
            'visual_quality' => true,
            'assembly' => false,
            'earth_bond_pe_fe' => true,
            'required_size' => '25',
            'test_pe_l123n_r1' => '0.5',
            'test_pe_l123n_r2' => '0.6',
            'test_l2_l3_r1' => '1.1',
            'test_l2_l3_r2' => '1.2',
        ]);

        $line->refresh();

        $this->assertTrue($line->visual_quality);
        $this->assertFalse($line->assembly);
        $this->assertTrue($line->earth_bond_pe_fe);
        $this->assertSame('25', $line->required_size);
        $this->assertSame('0.5', $line->test_pe_l123n_r1);
        $this->assertSame('0.6', $line->test_pe_l123n_r2);
        $this->assertSame('1.1', $line->test_l2_l3_r1);
        $this->assertSame('1.2', $line->test_l2_l3_r2);
    }

    public function test_connection_type_column_is_gone(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('quality_sheets', 'connection_type'),
        );
    }
}
