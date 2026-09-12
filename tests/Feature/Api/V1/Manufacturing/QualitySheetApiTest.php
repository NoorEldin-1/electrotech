<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Manufacturing;

use App\Enums\QualitySheetStatus;
use App\Enums\WorkOrderStatus;
use App\Models\ProductionEntry;
use App\Models\QualitySheet;
use App\Models\WorkOrder;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * ورقة الجودة and الإنتاج والفاقد — the test record that becomes a certificate,
 * and the read-only production history behind the loss report.
 */
class QualitySheetApiTest extends ApiTestCase
{
    public function test_opening_a_sheet_is_idempotent_and_snapshots_the_order_specs(): void
    {
        $order = WorkOrder::factory()->create([
            'protection_degree' => 'IP54',
            'poles_count' => 4,
        ]);

        $first = $this->actingAsApi($this->userWith(['quality_sheets.create']))
            ->apiPost(self::BASE.'/work-orders/'.$order->id.'/quality-sheet');

        $first->assertOk();
        $first->assertJsonPath('data.status.value', 'draft');
        $first->assertJsonPath('data.specs.protection_degree', 'IP54');
        $first->assertJsonPath('data.specs.poles_count', 4);

        // Seeded with the paper form's grid so QA has rows to type into.
        $this->assertCount(10, $first->json('data.lines'));

        // Calling again returns the SAME sheet. A second certificate for one
        // order would make "the" quality sheet meaningless.
        $second = $this->actingAsApi($this->userWith(['quality_sheets.create']))
            ->apiPost(self::BASE.'/work-orders/'.$order->id.'/quality-sheet');

        $second->assertOk();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, $order->qualitySheets()->count());
    }

    public function test_the_spec_snapshot_does_not_follow_later_edits_of_the_order(): void
    {
        $order = WorkOrder::factory()->create(['protection_degree' => 'IP54']);

        $sheet = $this->actingAsApi($this->userWith(['quality_sheets.create']))
            ->apiPost(self::BASE.'/work-orders/'.$order->id.'/quality-sheet')
            ->json('data.id');

        $order->update(['protection_degree' => 'IP66']);

        // A certificate must keep saying what was actually tested.
        $this->actingAsApi($this->userWith(['quality_sheets.view']))
            ->apiGet(self::BASE.'/quality-sheets/'.$sheet)
            ->assertOk()
            ->assertJsonPath('data.specs.protection_degree', 'IP54');
    }

    public function test_it_round_trips_the_test_grid(): void
    {
        $sheet = QualitySheet::factory()->create(['status' => QualitySheetStatus::Draft]);

        $response = $this->actingAsApi($this->userWith(['quality_sheets.create']))
            ->apiJson('PUT', self::BASE.'/quality-sheets/'.$sheet->id.'/lines', [
                'lines' => [
                    [
                        'line_no' => 1,
                        'label' => 'Panel section A',
                        'piece_number' => '4',
                        'required_size' => '16mm',
                        'checks' => [
                            'visual_quality' => true,
                            'assembly' => true,
                            'earth_bond_pe_fe' => false,
                        ],
                        'tests' => [
                            'pe_l123n' => ['r1' => '0.12', 'r2' => '0.13'],
                            'l2_l3' => ['r1' => 'OK', 'r2' => '—'],
                        ],
                        'notes' => 'Retested after rework',
                    ],
                ],
            ]);

        $response->assertOk();

        // The write shape and the read shape are the same shape, so a client
        // can fetch, edit and send back without a translation layer.
        $response->assertJsonPath('data.lines.0.checks.visual_quality', true);
        $response->assertJsonPath('data.lines.0.checks.earth_bond_pe_fe', false);
        $response->assertJsonPath('data.lines.0.tests.pe_l123n.r1', '0.12');
        $response->assertJsonPath('data.lines.0.tests.pe_l123n.r2', '0.13');

        // Readings are strings: the sheet records what the meter showed, and
        // the paper form it replaces carries entries like "OK" and a dash.
        $response->assertJsonPath('data.lines.0.tests.l2_l3.r1', 'OK');
        $response->assertJsonPath('data.lines.0.tests.l2_l3.r2', '—');

        // Untouched tests come back as nulls rather than being dropped, so the
        // grid keeps its shape.
        $response->assertJsonPath('data.lines.0.tests.fe_l123n.r1', null);
    }

    public function test_the_lifecycle_runs_fill_then_approve(): void
    {
        $sheet = QualitySheet::factory()->create(['status' => QualitySheetStatus::Draft]);

        // Approval before QA has signed is refused by the POLICY, so the
        // answer is 403 — the same order the panel applies, where the action
        // is simply not offered yet.
        $this->actingAsApi($this->userWith(['quality_sheets.approve']))
            ->apiPost(self::BASE.'/quality-sheets/'.$sheet->id.'/approve')
            ->assertStatus(403);

        $this->actingAsApi($this->userWith(['quality_sheets.fill']))
            ->apiPost(self::BASE.'/quality-sheets/'.$sheet->id.'/fill', [
                'qa_inspector_notes' => 'Earth bond re-measured after rework',
            ])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'qa_filled')
            ->assertJsonPath('data.qa_inspector_notes', 'Earth bond re-measured after rework');

        $this->actingAsApi($this->userWith(['quality_sheets.approve']))
            ->apiPost(self::BASE.'/quality-sheets/'.$sheet->id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status.value', 'approved');

        $this->assertNotNull($sheet->fresh()->factory_approved_at);
    }

    public function test_an_approved_sheet_freezes(): void
    {
        $sheet = QualitySheet::factory()->create(['status' => QualitySheetStatus::Approved]);
        $user = $this->userWith(['quality_sheets.create', 'quality_sheets.fill']);

        // Editing and deleting are gated on `! isApproved()` in the policy, so
        // both answer 403 rather than a business rule.
        $this->actingAsApi($user)
            ->apiJson('PUT', self::BASE.'/quality-sheets/'.$sheet->id.'/lines', ['lines' => []])
            ->assertStatus(403);

        $this->actingAsApi($user)
            ->apiDelete(self::BASE.'/quality-sheets/'.$sheet->id)
            ->assertStatus(403);

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/quality-sheets/'.$sheet->id.'/fill')
            ->assertStatus(403);
    }

    public function test_filling_needs_its_own_permission(): void
    {
        $sheet = QualitySheet::factory()->create(['status' => QualitySheetStatus::Draft]);

        // Being allowed to author a sheet is not being allowed to certify it.
        $response = $this->actingAsApi($this->userWith(['quality_sheets.create']))
            ->apiPost(self::BASE.'/quality-sheets/'.$sheet->id.'/fill');

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'forbidden');
    }

    public function test_sheets_can_be_filtered_by_status_and_order(): void
    {
        $order = WorkOrder::factory()->create();
        QualitySheet::factory()->count(2)->create([
            'work_order_id' => $order->id,
            'status' => QualitySheetStatus::Draft,
        ]);
        QualitySheet::factory()->create(['status' => QualitySheetStatus::Approved]);

        $response = $this->actingAsApi($this->userWith(['quality_sheets.view']))
            ->apiGet(self::BASE.'/quality-sheets?filter[work_order]='.$order->id.'&filter[status]=draft');

        $response->assertOk();
        $this->assertPaginatedEnvelope($response);
        $response->assertJsonPath('meta.pagination.total', 2);
    }

    // --------------------------------------------------- production entries

    public function test_production_entries_are_readable_and_carry_the_loss_value(): void
    {
        $entry = ProductionEntry::factory()->create([
            'planned_quantity' => 10,
            'produced_quantity' => 9,
            'scrap_quantity' => 1,
            'planned_material_cost' => 40000,
            'actual_material_cost' => 43000,
        ]);

        $response = $this->actingAsApi($this->userWith(['production_entries.view']))
            ->apiGet(self::BASE.'/production-entries/'.$entry->id);

        $response->assertOk();
        $response->assertJsonPath('data.quantities.scrap', '1.0000');
        $response->assertJsonPath('data.quantities.scrap_percentage', '10.00');

        // الفاقد بالقيمة — what the scrap cost, published so every screen
        // aggregates the same number.
        $this->assertNotNull($response->json('data.costs.loss_value'));
    }

    public function test_production_entries_cannot_be_written_through_the_api(): void
    {
        $entry = ProductionEntry::factory()->create();
        $user = $this->userWith(['production_entries.view']);

        // There is no create/update/delete route at all: the policy answers
        // false to all three, and these rows are written by the work order's
        // completion at the same moment the stock moves.
        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/production-entries', [])
            ->assertStatus(405);

        $this->actingAsApi($user)
            ->apiDelete(self::BASE.'/production-entries/'.$entry->id)
            ->assertStatus(405);
    }

    public function test_completing_an_order_is_what_writes_a_production_entry(): void
    {
        $order = WorkOrder::factory()->create([
            'status' => WorkOrderStatus::QaReview,
            'qa_approved_by' => $this->admin()->id,
            'qa_approved_at' => now(),
            'planned_quantity' => 10,
            'produced_quantity' => 9,
            'waste_quantity' => 1,
        ]);

        $this->assertSame(0, $order->productionEntries()->count());

        $this->actingAsApi($this->userWith(['work_orders.complete']))
            ->apiPost(self::BASE.'/work-orders/'.$order->id.'/complete')
            ->assertOk();

        $this->assertSame(1, $order->productionEntries()->count());
    }

    public function test_unauthenticated_access_is_refused(): void
    {
        $this->apiGet(self::BASE.'/quality-sheets')->assertStatus(401);
        $this->apiGet(self::BASE.'/production-entries')->assertStatus(401);
    }
}
