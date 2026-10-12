<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralManagement;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Enums\AttachmentCategory;
use App\Enums\InstallationStatus;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Installation;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Project;
use App\Models\User;
use App\Services\InstallationService;
use App\Services\OperationCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InstallationTest extends TestCase
{
    use RefreshDatabase;

    public function test_lifecycle_start_then_complete(): void
    {
        $installation = Installation::factory()->create();
        $service = app(InstallationService::class);

        $service->start($installation);
        $installation->refresh();
        $this->assertSame(InstallationStatus::InProgress, $installation->status);
        $this->assertNotNull($installation->started_at);

        // Presentation.pptx سلايد 8: finished BY the works handover minutes.
        Attachment::factory()->create([
            'project_id' => $installation->project_id,
            'category' => AttachmentCategory::HandoverMinutes->value,
        ]);
        $installation->refresh();
        $this->assertSame(InstallationStatus::Completed, $installation->status);
        $this->assertNotNull($installation->completed_at);
    }

    public function test_an_installation_cannot_be_completed_without_handover_minutes(): void
    {
        $installation = Installation::factory()->create(['status' => InstallationStatus::InProgress, 'started_at' => now()]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(__('errors.installations.handover_minutes_required'));

        app(InstallationService::class)->complete($installation);
    }

    public function test_completing_with_a_minutes_file_files_it_on_the_operation(): void
    {
        $this->actingAs(User::factory()->create());
        Storage::fake('public');
        Storage::disk('public')->put('attachments/x/handover_minutes/minutes.pdf', 'pdf');

        $installation = Installation::factory()->create(['status' => InstallationStatus::InProgress, 'started_at' => now()]);

        app(InstallationService::class)->complete($installation, 'attachments/x/handover_minutes/minutes.pdf');

        $this->assertSame(InstallationStatus::Completed, $installation->fresh()->status);
        $this->assertDatabaseHas('attachments', [
            'project_id' => $installation->project_id,
            'category' => AttachmentCategory::HandoverMinutes->value,
            'file_name' => 'minutes.pdf',
        ]);
    }

    public function test_minutes_already_on_file_let_the_installation_complete(): void
    {
        $installation = Installation::factory()->create(['status' => InstallationStatus::InProgress, 'started_at' => now()]);
        Attachment::factory()->create([
            'project_id' => $installation->project_id,
            'category' => AttachmentCategory::HandoverMinutes->value,
        ]);

        // A second installation started after the minutes were filed.
        $later = Installation::factory()->create([
            'project_id' => $installation->project_id,
            'status' => InstallationStatus::InProgress,
            'started_at' => now(),
        ]);

        app(InstallationService::class)->complete($later);

        $this->assertSame(InstallationStatus::Completed, $later->fresh()->status);
    }

    public function test_cannot_complete_a_pending_installation(): void
    {
        $installation = Installation::factory()->create(); // pending

        $this->expectException(\DomainException::class);
        app(InstallationService::class)->complete($installation);
    }

    public function test_installation_expenses_sum_only_tagged_installation_account_lines(): void
    {
        $project = Project::factory()->active()->create();
        $installAccount = Account::factory()->ofType(AccountType::Expense)->create(['code' => '5020']);
        $otherExpense = Account::factory()->ofType(AccountType::Expense)->create(['code' => '5010']);

        $entry = JournalEntry::factory()->posted()->create();

        // Installation expense (account 5020) tagged to the operation — counts.
        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $installAccount->id,
            'project_id' => $project->id,
            'direction' => AccountDirection::Debit,
            'amount' => 700,
        ]);
        // Operating expense (5010) tagged — counts in ledgerExpenses but NOT installation.
        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $otherExpense->id,
            'project_id' => $project->id,
            'direction' => AccountDirection::Debit,
            'amount' => 200,
        ]);

        $service = app(OperationCostService::class);

        $this->assertEquals(700.0, $service->installationExpenses($project));
        // installation expenses are a subset of ledger expenses, not added on top.
        $this->assertEquals(900.0, $service->ledgerExpenses($project));
        $this->assertEquals(900.0, $service->breakdown($project)['ledger_expenses']);
        $this->assertEquals(700.0, $service->breakdown($project)['installation_expenses']);
    }
}
