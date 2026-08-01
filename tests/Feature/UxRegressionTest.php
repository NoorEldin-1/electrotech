<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\GeneralLedgerReport;
use App\Filament\Pages\JournalDaybook;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The UX half of the E2E report: §3.2 / §5.2 (inline validation messages) and
 * §5.4 (finance reports opening on an empty period).
 */
class UxRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        return $user;
    }

    // ---------------------------------------------------------------- §3.2

    public function test_the_panel_ships_the_inline_validation_script_and_its_messages(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin');

        $response->assertOk();
        $response->assertSee('js/inline-validation.js', escape: false);
        $response->assertSee('__etValidationMessages', escape: false);
    }

    public function test_the_client_validation_dictionary_is_complete_in_both_locales(): void
    {
        $keys = ['required', 'email', 'url', 'pattern', 'min_length', 'max_length', 'min', 'max', 'step', 'invalid'];

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            $messages = __('validation.client');

            $this->assertIsArray($messages, "validation.client is missing for {$locale}.");

            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $messages, "validation.client.{$key} is missing for {$locale}.");
                $this->assertNotSame('', trim($messages[$key]));
            }
        }
    }

    // ---------------------------------------------------------------- §5.4

    private function postEntryOn(string $date): void
    {
        JournalEntry::factory()->posted()->create(['entry_date' => $date]);
    }

    public function test_the_daybook_opens_on_the_current_month_when_it_has_postings(): void
    {
        $this->postEntryOn(Carbon::now()->startOfMonth()->addDays(3)->toDateString());

        Livewire::actingAs($this->admin())
            ->test(JournalDaybook::class)
            ->assertSet('from', Carbon::now()->startOfMonth()->toDateString())
            ->assertSet('to', Carbon::now()->endOfMonth()->toDateString());
    }

    /**
     * The defect itself: with nothing posted this month, the screen used to
     * open on an empty window and look broken.
     */
    public function test_the_daybook_falls_back_to_the_last_month_that_has_postings(): void
    {
        $past = Carbon::now()->subMonths(3);
        $this->postEntryOn($past->copy()->startOfMonth()->addDays(5)->toDateString());

        Livewire::actingAs($this->admin())
            ->test(JournalDaybook::class)
            ->assertSet('from', $past->copy()->startOfMonth()->toDateString())
            ->assertSet('to', $past->copy()->endOfMonth()->toDateString());
    }

    public function test_the_general_ledger_uses_the_same_period_rule(): void
    {
        Account::factory()->create(['is_active' => true]);

        $past = Carbon::now()->subMonths(2);
        $this->postEntryOn($past->copy()->startOfMonth()->addDay()->toDateString());

        Livewire::actingAs($this->admin())
            ->test(GeneralLedgerReport::class)
            ->assertSet('from', $past->copy()->startOfMonth()->toDateString())
            ->assertSet('to', $past->copy()->endOfMonth()->toDateString());
    }

    public function test_an_empty_ledger_still_opens_on_the_current_month(): void
    {
        Livewire::actingAs($this->admin())
            ->test(JournalDaybook::class)
            ->assertSet('from', Carbon::now()->startOfMonth()->toDateString());
    }
}
