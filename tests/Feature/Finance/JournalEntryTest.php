<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\AccountDirection;
use App\Enums\DocumentType;
use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\JournalEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalEntryTest extends TestCase
{
    use RefreshDatabase;

    private function entryWith(array $lines, array $attributes = []): JournalEntry
    {
        $entry = JournalEntry::factory()->create($attributes);

        foreach ($lines as [$accountId, $direction, $amount]) {
            JournalEntryLine::factory()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $accountId,
                'direction' => $direction,
                'amount' => $amount,
            ]);
        }

        return $entry->fresh('lines');
    }

    public function test_balanced_entry_posts_successfully(): void
    {
        $cash = Account::factory()->create();
        $sales = Account::factory()->create();

        $entry = $this->entryWith([
            [$cash->id, AccountDirection::Debit, 1000],
            [$sales->id, AccountDirection::Credit, 1000],
        ]);

        app(JournalEntryService::class)->post($entry);

        $entry->refresh();
        $this->assertSame(JournalStatus::Posted, $entry->status);
        $this->assertEquals(1000, (float) $entry->total_debit);
        $this->assertEquals(1000, (float) $entry->total_credit);
        $this->assertNotNull($entry->posted_at);
    }

    public function test_unbalanced_entry_is_rejected(): void
    {
        $cash = Account::factory()->create();
        $sales = Account::factory()->create();

        $entry = $this->entryWith([
            [$cash->id, AccountDirection::Debit, 1000],
            [$sales->id, AccountDirection::Credit, 700],
        ]);

        $this->expectException(\RuntimeException::class);
        app(JournalEntryService::class)->post($entry);

        $this->assertSame(JournalStatus::Draft, $entry->fresh()->status);
    }

    public function test_entry_with_single_line_is_rejected(): void
    {
        $cash = Account::factory()->create();

        $entry = $this->entryWith([
            [$cash->id, AccountDirection::Debit, 1000],
        ]);

        $this->expectException(\RuntimeException::class);
        app(JournalEntryService::class)->post($entry);
    }

    public function test_cannot_post_twice(): void
    {
        $cash = Account::factory()->create();
        $sales = Account::factory()->create();

        $entry = $this->entryWith([
            [$cash->id, AccountDirection::Debit, 500],
            [$sales->id, AccountDirection::Credit, 500],
        ]);

        app(JournalEntryService::class)->post($entry);

        $this->expectException(\RuntimeException::class);
        app(JournalEntryService::class)->post($entry->fresh('lines'));
    }

    public function test_entry_number_is_sequenced_per_document_type(): void
    {
        $first = JournalEntry::generateEntryNumber(DocumentType::PaymentOrder);
        JournalEntry::factory()->create(['entry_number' => $first, 'document_type' => DocumentType::PaymentOrder]);
        $second = JournalEntry::generateEntryNumber(DocumentType::PaymentOrder);

        $this->assertStringStartsWith('PV-', $first);
        $this->assertNotSame($first, $second);
        $this->assertStringEndsWith('0002', $second);

        // A different document type keeps its own sequence.
        $receipt = JournalEntry::generateEntryNumber(DocumentType::SupplyReceipt);
        $this->assertStringStartsWith('RV-', $receipt);
        $this->assertStringEndsWith('0001', $receipt);
    }

    public function test_document_type_colors_match_spec(): void
    {
        $this->assertSame('gray', DocumentType::PaymentOrder->getColor());     // أسود
        $this->assertSame('danger', DocumentType::SupplyReceipt->getColor());  // أحمر
        $this->assertSame('success', DocumentType::Settlement->getColor());    // أخضر
    }

    public function test_is_balanced_helper(): void
    {
        $a = Account::factory()->create();
        $b = Account::factory()->create();

        $balanced = $this->entryWith([
            [$a->id, AccountDirection::Debit, 250],
            [$b->id, AccountDirection::Credit, 250],
        ]);
        $this->assertTrue($balanced->isBalanced());

        $unbalanced = $this->entryWith([
            [$a->id, AccountDirection::Debit, 250],
            [$b->id, AccountDirection::Credit, 100],
        ]);
        $this->assertFalse($unbalanced->isBalanced());
    }
}
