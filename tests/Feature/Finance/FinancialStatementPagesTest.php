<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\StatementSection;
use App\Filament\Pages\BalanceSheet;
use App\Filament\Pages\CashFlowStatement;
use App\Filament\Pages\IncomeStatement;
use App\Filament\Pages\OperatingStatement;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The four statement screens: who can open them, that they render, and that
 * every label they print exists in both locales.
 */
class FinancialStatementPagesTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, class-string> permission ⇒ page */
    private const PAGES = [
        'operating_statement.view' => OperatingStatement::class,
        'income_statement.view' => IncomeStatement::class,
        'balance_sheet.view' => BalanceSheet::class,
        'cash_flow_statement.view' => CashFlowStatement::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_finance_and_general_manager_can_read_all_four_statements(): void
    {
        foreach (['Admin', 'Finance', 'General_Manager'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            foreach (array_keys(self::PAGES) as $permission) {
                $this->assertTrue($user->can($permission), "{$role} is missing {$permission}");
            }
        }
    }

    public function test_roles_outside_finance_cannot_read_the_statements(): void
    {
        foreach (['Sales', 'Procurement', 'Warehouse_Manager', 'Factory_Manager'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            foreach (array_keys(self::PAGES) as $permission) {
                $this->assertFalse($user->can($permission), "{$role} should not have {$permission}");
            }
        }
    }

    public function test_each_page_is_gated_by_its_own_permission(): void
    {
        foreach (self::PAGES as $permission => $page) {
            $user = User::factory()->create();
            $user->givePermissionTo($permission);
            $this->actingAs($user);

            $this->assertTrue($page::canAccess(), "{$page} should open for {$permission}");

            $other = User::factory()->create();
            $other->givePermissionTo('trial_balance.view');
            $this->actingAs($other);

            $this->assertFalse($page::canAccess(), "{$page} must not open without {$permission}");
        }
    }

    public function test_every_statement_page_renders(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Finance');
        $this->actingAs($user);

        foreach (self::PAGES as $page) {
            Livewire::test($page)->assertOk();
        }
    }

    public function test_the_pdf_route_rejects_an_unknown_statement_and_an_unauthorised_user(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Finance');

        $this->actingAs($user)
            ->get(route('finance.statements.pdf', ['statement' => 'not-a-statement']))
            ->assertNotFound();

        $outsider = User::factory()->create();
        $outsider->assignRole('Sales');

        $this->actingAs($outsider)
            ->get(route('finance.statements.pdf', ['statement' => 'balance_sheet']))
            ->assertForbidden();
    }

    /**
     * A guest — or anyone whose session expired while a report tab sat open —
     * must be sent to the login screen, not handed a blank 500.
     *
     * Laravel's `auth` middleware redirects to a route named `login`, which
     * this application does not have (the login screen belongs to the Filament
     * panel). Without the redirectGuestsTo mapping in bootstrap/app.php, every
     * print link in the system threw RouteNotFoundException for a guest.
     */
    public function test_guests_are_redirected_to_the_login_screen_not_a_server_error(): void
    {
        $this->get(route('finance.statements.pdf', ['statement' => 'income']))
            ->assertRedirect(route('filament.admin.auth.login'));

        // The same guard covers the other printable documents, so assert one
        // of the pre-existing ones too — this is app-wide behaviour, not a
        // quirk of the statements controller.
        $this->get(route('finance.general_ledger.pdf', ['account' => 1]))
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_every_statement_label_is_translated_in_both_locales(): void
    {
        $keys = [
            'resources.operating_statement.title',
            'resources.operating_statement.cost_of_sales',
            'resources.operating_statement.chart_note',
            'resources.income_statement.title',
            'resources.income_statement.formula_note',
            'resources.balance_sheet.title',
            'resources.balance_sheet.balanced',
            'resources.balance_sheet.period_profit',
            'resources.cash_flow_statement.title',
            'resources.cash_flow_statement.reconciled',
            'resources.cash_flow_statement.no_adjustment',
            'resources.cash_flow_statement.formula_note.heading_client',
            'resources.cash_flow_statement.formula_note.body_client',
            'resources.cash_flow_statement.formula_note.heading_add_back',
            'resources.cash_flow_statement.formula_note.body_add_back',
            'resources.financial_statements.columns.partial',
            'resources.financial_statements.columns.total',
            'resources.operation_timeline.heading',
            'resources.accounts.fields.statement_section',
            'resources.accounts.filters.unclassified',
        ];

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ($keys as $key) {
                $this->assertNotSame($key, __($key), "[{$locale}] missing translation: {$key}");
            }

            // Every income-statement row label and every statement section.
            foreach ([
                'net_sales', 'sales', 'sales_returns', 'cost_of_sales', 'gross_profit',
                'other_revenue', 'capital_gains', 'fx_gain', 'total_revenues', 'general_admin',
                'fx_loss', 'depreciation', 'closed_letters_of_credit', 'finance_cost',
                'total_expenses', 'net_profit',
            ] as $row) {
                $key = "resources.income_statement.rows.{$row}";
                $this->assertNotSame($key, __($key), "[{$locale}] missing income row: {$row}");
            }

            foreach (StatementSection::cases() as $section) {
                $key = 'resources.enums.statement_section.' . $section->value;
                $this->assertNotSame($key, __($key), "[{$locale}] missing section label: {$section->value}");
            }
        }

        app()->setLocale('en');
    }
}
