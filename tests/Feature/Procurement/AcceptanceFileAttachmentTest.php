<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Filament\Resources\InHandProjectResource;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Slide 11: moving an operation forward attaches the customer-acceptance / SMB
 * file (the document, not just a note) as a project attachment.
 */
class AcceptanceFileAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_acceptance_file_is_stored_as_a_project_attachment(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());

        $project = Project::factory()->create();
        $path = "attachments/{$project->id}/customer_acceptance/accept.pdf";
        Storage::disk('public')->put($path, 'x');

        $method = new ReflectionMethod(InHandProjectResource::class, 'storeAcceptanceFile');
        $method->setAccessible(true);
        $method->invoke(null, $project, $path);

        $this->assertDatabaseHas('attachments', [
            'project_id' => $project->id,
            'category' => 'customer_acceptance',
            'file_name' => 'accept.pdf',
        ]);
    }

    public function test_no_file_creates_no_attachment(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->create();

        $method = new ReflectionMethod(InHandProjectResource::class, 'storeAcceptanceFile');
        $method->setAccessible(true);
        $method->invoke(null, $project, null);

        $this->assertSame(0, $project->attachments()->count());
    }
}
