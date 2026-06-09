<?php

namespace Tests\Feature\Sales;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: creating an offer through the relationship (as the Offers
 * repeater on the Project form does) must not require a Hidden submitted_by —
 * it defaults to the authenticated user, otherwise the NOT NULL column blows
 * up with a 23000 integrity-constraint violation.
 */
class OfferDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_offer_defaults_submitted_by_to_current_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = Project::factory()->create();

        $offer = $project->offers()->create([
            'financial_amount' => 1111,
            'notes' => 'created without an explicit submitted_by',
        ]);

        $fresh = $offer->fresh();
        $this->assertSame($user->id, $fresh->submitted_by);
        $this->assertNotNull($fresh->submitted_at);
        $this->assertSame(1, $fresh->version);
    }

    public function test_explicit_submitted_by_is_preserved(): void
    {
        $actor = User::factory()->create();
        $original = User::factory()->create();
        $this->actingAs($actor);

        $project = Project::factory()->create();

        $offer = $project->offers()->create([
            'financial_amount' => 2222,
            'submitted_by' => $original->id,
        ]);

        $this->assertSame($original->id, $offer->fresh()->submitted_by);
    }
}
