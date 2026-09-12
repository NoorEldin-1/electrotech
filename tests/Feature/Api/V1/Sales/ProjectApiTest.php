<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Sales;

use App\Enums\ProjectStatus;
use App\Models\Bom;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectOffer;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\V1\ApiTestCase;

class ProjectApiTest extends ApiTestCase
{
    // ------------------------------------------------------------ happy path

    public function test_it_lists_operations(): void
    {
        Project::factory()->count(3)->create();

        $response = $this->actingAsApi($this->userWith(['projects.view']))
            ->apiGet(self::BASE.'/projects');

        $response->assertOk();
        $this->assertPaginatedEnvelope($response);
        $response->assertJsonPath('meta.pagination.total', 3);
    }

    public function test_it_serializes_an_operation_to_the_documented_shape(): void
    {
        $project = Project::factory()->create([
            'name' => 'Substation A',
            'status' => ProjectStatus::Tender,
            'estimated_budget' => 1250000,
        ]);

        $response = $this->actingAsApi($this->userWith(['projects.view']))
            ->apiGet(self::BASE.'/projects/'.$project->id);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Substation A');
        $response->assertJsonPath('data.status.value', 'tender');
        $response->assertJsonPath('data.estimated_budget', '1250000.00');
        $response->assertJsonPath('data.has_smb', false);
    }

    public function test_it_creates_an_operation_with_a_server_generated_code(): void
    {
        $response = $this->actingAsApi($this->userWith(['projects.create']))
            ->apiPost(self::BASE.'/projects', [
                'name' => 'Substation B',
                'client_name' => 'Delta Contracting',
            ]);

        $response->assertCreated();

        // The code is minted server-side (YYYY-N) — two clients racing to
        // create an operation must not be able to pick the same number.
        $this->assertMatchesRegularExpression('/^\d{4}-\d+$/', $response->json('data.code'));

        // A new operation lands in Tender, not Draft (the model enforces it).
        $response->assertJsonPath('data.status.value', 'tender');
    }

    public function test_the_client_cannot_choose_the_code_or_the_status(): void
    {
        $response = $this->actingAsApi($this->userWith(['projects.create']))
            ->apiPost(self::BASE.'/projects', [
                'name' => 'Sneaky',
                'client_name' => 'Delta',
                'code' => 'HAND-PICKED',
                // Skipping the whole pipeline by posting the end state is
                // exactly what the transition endpoints exist to prevent.
                'status' => 'in_progress',
            ]);

        $response->assertCreated();
        $this->assertNotSame('HAND-PICKED', $response->json('data.code'));
        $response->assertJsonPath('data.status.value', 'tender');
    }

    public function test_it_updates_an_operation(): void
    {
        $project = Project::factory()->create(['name' => 'Old']);

        $this->actingAsApi($this->userWith(['projects.edit']))
            ->apiPatch(self::BASE.'/projects/'.$project->id, ['name' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New');
    }

    public function test_a_patch_cannot_move_the_operation_between_stages(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Tender]);

        $this->actingAsApi($this->userWith(['projects.edit']))
            ->apiPatch(self::BASE.'/projects/'.$project->id, ['status' => 'in_progress'])
            ->assertOk();

        $this->assertSame(ProjectStatus::Tender, $project->fresh()->status);
    }

    public function test_it_deletes_an_operation_with_no_downstream_documents(): void
    {
        $project = Project::factory()->create();

        $this->actingAsApi($this->userWith(['projects.delete']))
            ->apiDelete(self::BASE.'/projects/'.$project->id)
            ->assertNoContent();

        $this->assertSoftDeleted('projects', ['id' => $project->id]);
    }

    // --------------------------------------------------------- business rule

    public function test_it_refuses_to_delete_an_operation_that_has_downstream_documents(): void
    {
        $project = Project::factory()->create();
        Bom::factory()->create(['project_id' => $project->id]);

        $response = $this->actingAsApi($this->userWith(['projects.delete']))
            ->apiDelete(self::BASE.'/projects/'.$project->id);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
    }

    // ----------------------------------------------------------- validation

    public function test_it_requires_a_name_and_client(): void
    {
        $response = $this->actingAsApi($this->userWith(['projects.create']))
            ->apiPost(self::BASE.'/projects', []);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'validation_failed');
        $response->assertJsonStructure(['error' => ['details' => ['name', 'client_name']]]);
    }

    public function test_it_rejects_a_customer_id_that_does_not_exist(): void
    {
        $response = $this->actingAsApi($this->userWith(['projects.create']))
            ->apiPost(self::BASE.'/projects', [
                'name' => 'X',
                'client_name' => 'Y',
                'customer_id' => 99999,
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error' => ['details' => ['customer_id']]]);
    }

    // ------------------------------------------------------------------ RBAC

    public function test_operation_endpoints_are_permission_gated(): void
    {
        $project = Project::factory()->create();
        $user = $this->userWithoutPermissions();

        $this->actingAsApi($user)->apiGet(self::BASE.'/projects')->assertForbidden();
        $this->actingAsApi($user)->apiGet(self::BASE.'/projects/'.$project->id)->assertForbidden();
        $this->actingAsApi($user)->apiPost(self::BASE.'/projects', [])->assertForbidden();
        $this->actingAsApi($user)->apiPatch(self::BASE.'/projects/'.$project->id, [])->assertForbidden();
        $this->actingAsApi($user)->apiDelete(self::BASE.'/projects/'.$project->id)->assertForbidden();
    }

    public function test_a_token_without_the_sales_ability_is_refused(): void
    {
        $response = $this->actingAsApi($this->userWith(['projects.view']), ['inventory'])
            ->apiGet(self::BASE.'/projects');

        $response->assertForbidden();
        $this->assertErrorEnvelope($response, 'insufficient_token_ability');
    }

    // ------------------------------------------------- query features / perf

    public function test_it_filters_by_pipeline_stage(): void
    {
        Project::factory()->create(['status' => ProjectStatus::Tender]);
        Project::factory()->create(['status' => ProjectStatus::InHand]);
        Project::factory()->create(['status' => ProjectStatus::Lost]);

        $this->actingAsApi($this->userWith(['projects.view']))
            ->apiGet(self::BASE.'/projects?filter[status]=tender,in_hand')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2);
    }

    public function test_it_filters_operations_missing_a_priced_offer(): void
    {
        $priced = Project::factory()->create();
        ProjectOffer::factory()->create([
            'project_id' => $priced->id,
            'financial_amount' => 1000,
        ]);

        Project::factory()->create();

        $response = $this->actingAsApi($this->userWith(['projects.view']))
            ->apiGet(self::BASE.'/projects?filter[missing_offer]=true');

        $response->assertOk();
        // The same scope the Sales bell uses, so the app and the panel cannot
        // disagree about which operations still need a price.
        $response->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_the_index_query_count_does_not_grow_with_the_number_of_rows(): void
    {
        // A fixed ceiling would be a magic number that drifts every time the
        // permission cache or the auth path changes a query. What actually
        // defines an N+1 is that the count *scales*, so this measures the same
        // endpoint over 3 rows and over 9 and asserts the two agree.
        $this->actingAsApi($this->userWith(['projects.view']));

        // One throwaway call first. The very first request of a test also
        // populates Spatie's permission cache and resolves the token, so
        // measuring it would compare a cold run against a warm one and report
        // *fewer* queries for more rows — which looks like a passing N+1 test
        // failing, and is really just the warm-up.
        $this->apiGet(self::BASE.'/projects')->assertOk();

        $measure = function (int $rows): int {
            Project::query()->forceDelete();

            $customer = Customer::factory()->create();

            foreach (Project::factory()->count($rows)->create(['customer_id' => $customer->id]) as $project) {
                ProjectOffer::factory()->create(['project_id' => $project->id]);
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->apiGet(self::BASE.'/projects?include=customer,latestOffer')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $small = $measure(3);
        $large = $measure(9);

        $this->assertSame(
            $small,
            $large,
            "The projects index issued {$small} queries for 3 rows and {$large} for 9 — that is an N+1.",
        );
    }

    // -------------------------------------------------------------- auth

    public function test_it_requires_a_token(): void
    {
        $this->apiGet(self::BASE.'/projects')->assertUnauthorized();
    }
}
