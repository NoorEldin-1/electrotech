<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\DocumentType;
use App\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * رقم القيد ورقم المستند (قائمة المواد سلايد 2): every entry gets a plain
 * running serial, and each document type keeps its own document-number
 * sequence which the user may still override by hand.
 */
class JournalNumberingTest extends TestCase
{
    use RefreshDatabase;

    public function test_entry_serial_runs_sequentially_across_document_types(): void
    {
        $first = JournalEntry::factory()->ofType(DocumentType::PaymentOrder)->create(['document_number' => null]);
        $second = JournalEntry::factory()->ofType(DocumentType::Settlement)->create(['document_number' => null]);
        $third = JournalEntry::factory()->ofType(DocumentType::PaymentOrder)->create(['document_number' => null]);

        $this->assertSame(1, $first->entry_serial);
        $this->assertSame(2, $second->entry_serial);
        $this->assertSame(3, $third->entry_serial);
    }

    public function test_document_number_sequence_is_kept_per_document_type(): void
    {
        // A book that already reached 3140 for payment orders and 160 for
        // settlements — the numbers from the client's sample.
        JournalEntry::factory()->ofType(DocumentType::PaymentOrder)->create(['document_number' => '3140']);
        JournalEntry::factory()->ofType(DocumentType::Settlement)->create(['document_number' => '160']);

        $payment = JournalEntry::factory()->ofType(DocumentType::PaymentOrder)->create(['document_number' => null]);
        $settlement = JournalEntry::factory()->ofType(DocumentType::Settlement)->create(['document_number' => null]);

        $this->assertSame('3141', $payment->document_number);
        $this->assertSame('161', $settlement->document_number);
    }

    public function test_manual_document_number_is_kept_and_non_numeric_refs_do_not_shift_the_counter(): void
    {
        JournalEntry::factory()->ofType(DocumentType::PaymentOrder)->create(['document_number' => '3140']);

        $manual = JournalEntry::factory()->ofType(DocumentType::PaymentOrder)->create(['document_number' => 'TRF/12']);
        $next = JournalEntry::factory()->ofType(DocumentType::PaymentOrder)->create(['document_number' => null]);

        $this->assertSame('TRF/12', $manual->document_number);
        $this->assertSame('3141', $next->document_number);
    }

    public function test_first_entry_of_a_type_starts_at_one(): void
    {
        $entry = JournalEntry::factory()->ofType(DocumentType::SupplyReceipt)->create(['document_number' => null]);

        $this->assertSame('1', $entry->document_number);
    }
}
