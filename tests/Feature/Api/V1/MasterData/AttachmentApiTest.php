<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\MasterData;

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Supplier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * The attachments endpoint is the one place in Module 2 where getting
 * authorization wrong would publish documents rather than merely break a
 * screen, so most of what is asserted here is what the endpoint *refuses*.
 */
class AttachmentApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    // ------------------------------------------------------------ happy path

    public function test_it_uploads_a_document_against_a_supplier(): void
    {
        $supplier = Supplier::factory()->create();

        $response = $this->actingAsApi($this->userWith(['suppliers.view', 'suppliers.edit']))
            ->apiUpload(self::BASE.'/attachments', [
                'owner_type' => 'supplier',
                'owner_id' => $supplier->id,
                'category' => 'tax_card',
                'file' => UploadedFile::fake()->create('tax-card.pdf', 120, 'application/pdf'),
            ]);

        $response->assertCreated();
        $this->assertItemEnvelope($response);

        // The display name is the client's; the stored name is not.
        $response->assertJsonPath('data.file_name', 'tax-card.pdf');
        $response->assertJsonPath('data.owner.type', 'supplier');
        $response->assertJsonPath('data.owner.id', $supplier->id);

        $attachment = Attachment::sole();
        $this->assertSame($supplier->getMorphClass(), $attachment->attachable_type);

        // Written into the same folder tree the Filament pages use, so the
        // panel finds a file the phone uploaded.
        $this->assertStringStartsWith(
            "supplier-attachments/{$supplier->id}/tax_card/",
            $attachment->file_path,
        );
        Storage::disk('public')->assertExists($attachment->file_path);
    }

    public function test_the_stored_filename_is_generated_not_the_clients(): void
    {
        $supplier = Supplier::factory()->create();

        $this->actingAsApi($this->userWith(['suppliers.view', 'suppliers.edit']))
            ->apiUpload(self::BASE.'/attachments', [
                'owner_type' => 'supplier',
                'owner_id' => $supplier->id,
                'category' => 'tax_card',
                // A filename is attacker-controlled text that becomes a path
                // segment. Storing it verbatim is a traversal waiting to
                // happen, and two people uploading "scan.pdf" would collide.
                'file' => UploadedFile::fake()->create('../../evil name.pdf', 10, 'application/pdf'),
            ]);

        $attachment = Attachment::sole();

        $this->assertStringNotContainsString('..', $attachment->file_path);
        $this->assertStringNotContainsString(' ', $attachment->file_path);
        $this->assertStringEndsWith('.pdf', $attachment->file_path);
    }

    public function test_it_lists_a_records_attachments(): void
    {
        $supplier = Supplier::factory()->create();
        $user = $this->userWith(['suppliers.view']);

        $supplier->attachments()->create([
            'file_name' => 'registry.pdf',
            'file_path' => 'supplier-attachments/1/commercial_registry/a.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 100,
            'category' => 'commercial_registry',
            'uploaded_by' => $user->id,
        ]);

        $response = $this->actingAsApi($user)->apiGet(
            self::BASE."/attachments?owner_type=supplier&owner_id={$supplier->id}",
        );

        $response->assertOk();
        $response->assertJsonPath('meta.count', 1);
        $response->assertJsonPath('data.0.file_name', 'registry.pdf');

        // The raw storage path is never published — only a routed URL that
        // re-checks the owner's policy on every fetch.
        $response->assertJsonMissingPath('data.0.file_path');
        $this->assertStringContainsString('/attachments/', $response->json('data.0.download_url'));
    }

    public function test_it_downloads_the_file_through_the_policy_gate(): void
    {
        $supplier = Supplier::factory()->create();
        $user = $this->userWith(['suppliers.view']);

        Storage::disk('public')->put('supplier-attachments/x.pdf', 'the bytes');

        $attachment = $supplier->attachments()->create([
            'file_name' => 'registry.pdf',
            'file_path' => 'supplier-attachments/x.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 9,
            'category' => 'commercial_registry',
            'uploaded_by' => $user->id,
        ]);

        $response = $this->actingAsApi($user)
            ->apiGet(self::BASE.'/attachments/'.$attachment->id.'/download');

        $response->assertOk();
        $this->assertSame('the bytes', $response->streamedContent());
    }

    public function test_it_deletes_the_row_and_the_file(): void
    {
        $supplier = Supplier::factory()->create();
        $user = $this->userWith(['suppliers.view', 'suppliers.edit']);

        Storage::disk('public')->put('supplier-attachments/y.pdf', 'bytes');

        $attachment = $supplier->attachments()->create([
            'file_name' => 'registry.pdf',
            'file_path' => 'supplier-attachments/y.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 5,
            'category' => 'commercial_registry',
            'uploaded_by' => $user->id,
        ]);

        $this->actingAsApi($user)
            ->apiDelete(self::BASE.'/attachments/'.$attachment->id)
            ->assertNoContent();

        $this->assertSoftDeleted('attachments', ['id' => $attachment->id]);
        Storage::disk('public')->assertMissing('supplier-attachments/y.pdf');
    }

    public function test_a_project_attachment_uses_the_project_id_column(): void
    {
        $project = Project::factory()->create();

        $this->actingAsApi($this->userWith(['projects.view', 'projects.edit']))
            ->apiUpload(self::BASE.'/attachments', [
                'owner_type' => 'project',
                'owner_id' => $project->id,
                'category' => 'boq',
                'file' => UploadedFile::fake()->create('boq.xlsx', 20),
            ])
            ->assertCreated()
            ->assertJsonPath('data.owner.type', 'project');

        // Projects predate the polymorphic columns and were deliberately left
        // on `project_id`; the API must honour that, not start a second scheme.
        $attachment = Attachment::sole();
        $this->assertSame($project->id, $attachment->project_id);
        $this->assertNull($attachment->attachable_type);
    }

    // --------------------------------------------------------------- refusals

    public function test_it_refuses_an_owner_type_outside_the_whitelist(): void
    {
        $response = $this->actingAsApi($this->admin())
            ->apiUpload(self::BASE.'/attachments', [
                // Without the whitelist this would attach a row to any model
                // in the app, then read it back through an endpoint whose
                // authorization was written with suppliers in mind.
                'owner_type' => 'App\\Models\\User',
                'owner_id' => 1,
                'category' => 'tax_card',
                'file' => UploadedFile::fake()->create('x.pdf', 5),
            ]);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'validation_failed');
        $response->assertJsonStructure(['error' => ['details' => ['owner_type']]]);
    }

    public function test_uploading_requires_permission_to_edit_the_owner(): void
    {
        $supplier = Supplier::factory()->create();

        // Read-only on suppliers: may look at the file, may not add one.
        $response = $this->actingAsApi($this->userWith(['suppliers.view']))
            ->apiUpload(self::BASE.'/attachments', [
                'owner_type' => 'supplier',
                'owner_id' => $supplier->id,
                'category' => 'tax_card',
                'file' => UploadedFile::fake()->create('x.pdf', 5),
            ]);

        $response->assertForbidden();
        $this->assertErrorEnvelope($response, 'forbidden');
        $this->assertSame(0, Attachment::count());
    }

    public function test_reading_requires_permission_to_view_the_owner(): void
    {
        $supplier = Supplier::factory()->create();
        $owner = $this->userWith(['suppliers.view']);

        $attachment = $supplier->attachments()->create([
            'file_name' => 'registry.pdf',
            'file_path' => 'supplier-attachments/z.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 5,
            'category' => 'commercial_registry',
            'uploaded_by' => $owner->id,
        ]);

        $stranger = $this->userWithoutPermissions();

        $this->actingAsApi($stranger)
            ->apiGet(self::BASE."/attachments?owner_type=supplier&owner_id={$supplier->id}")
            ->assertForbidden();

        $this->actingAsApi($stranger)
            ->apiGet(self::BASE.'/attachments/'.$attachment->id.'/download')
            ->assertForbidden();

        $this->actingAsApi($stranger)
            ->apiDelete(self::BASE.'/attachments/'.$attachment->id)
            ->assertForbidden();
    }

    public function test_it_rejects_a_file_type_outside_the_allow_list(): void
    {
        $supplier = Supplier::factory()->create();

        $response = $this->actingAsApi($this->userWith(['suppliers.view', 'suppliers.edit']))
            ->apiUpload(self::BASE.'/attachments', [
                'owner_type' => 'supplier',
                'owner_id' => $supplier->id,
                'category' => 'tax_card',
                'file' => UploadedFile::fake()->create('payload.php', 5, 'application/x-php'),
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error' => ['details' => ['file']]]);
    }

    public function test_an_unknown_owner_id_is_a_422_not_a_404(): void
    {
        // The caller addressed /attachments, which exists. It is the body that
        // names something that does not.
        $response = $this->actingAsApi($this->admin())
            ->apiGet(self::BASE.'/attachments?owner_type=supplier&owner_id=99999');

        $response->assertStatus(422);
        $response->assertJsonStructure(['error' => ['details' => ['owner_id']]]);
    }

    public function test_a_row_whose_file_vanished_is_a_404(): void
    {
        $supplier = Supplier::factory()->create();
        $user = $this->userWith(['suppliers.view']);

        $attachment = $supplier->attachments()->create([
            'file_name' => 'gone.pdf',
            'file_path' => 'supplier-attachments/never-written.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 5,
            'category' => 'commercial_registry',
            'uploaded_by' => $user->id,
        ]);

        $response = $this->actingAsApi($user)
            ->apiGet(self::BASE.'/attachments/'.$attachment->id.'/download');

        $response->assertNotFound();
        $this->assertErrorEnvelope($response, 'not_found');
    }

    public function test_it_requires_a_token(): void
    {
        $this->apiGet(self::BASE.'/attachments?owner_type=supplier&owner_id=1')
            ->assertUnauthorized();
    }
}
