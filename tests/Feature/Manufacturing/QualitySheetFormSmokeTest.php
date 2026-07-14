<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Filament\Resources\QualitySheetResource\Pages\EditQualitySheet;
use App\Models\QualitySheet;
use App\Models\QualitySheetLine;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The reworked quality-sheet form (new header spec fields, the checkbox line
 * columns and the two-reading Fieldset groups) renders without error.
 */
class QualitySheetFormSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
    }

    public function test_quality_sheet_form_renders(): void
    {
        $sheet = QualitySheet::factory()->create();
        QualitySheetLine::factory()->create([
            'quality_sheet_id' => $sheet->id,
            'test_pe_l123n_r1' => '0.5',
            'test_pe_l123n_r2' => '0.6',
        ]);

        Livewire::test(EditQualitySheet::class, ['record' => $sheet->getRouteKey()])->assertOk();
    }
}
