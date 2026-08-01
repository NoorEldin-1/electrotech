<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\SupplierResource\Pages\CreateSupplier;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * E2E report §8 — "Multiple customers and suppliers share the same email
 * (jackswim2004@gmail.com) and phone number, suggesting no uniqueness
 * constraint on email/phone." A duplicated party splits its projects,
 * invoices and paperwork across two files.
 */
class DuplicateContactGuardTest extends TestCase
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

    public function test_a_customer_cannot_reuse_another_customers_email(): void
    {
        Customer::factory()->create(['email' => 'jackswim2004@gmail.com']);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'ZZZ Duplicate', 'email' => 'jackswim2004@gmail.com'])
            ->call('create')
            ->assertHasFormErrors(['email']);

        $this->assertSame(1, Customer::query()->count());
    }

    public function test_a_customer_cannot_reuse_another_customers_phone(): void
    {
        Customer::factory()->create(['phone' => '01001234567']);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'ZZZ Duplicate', 'phone' => '01001234567'])
            ->call('create')
            ->assertHasFormErrors(['phone']);
    }

    /**
     * The phone check must run on the NORMALISED value, otherwise the very
     * users PhoneInput exists for — those typing Arabic-Indic numerals — slip
     * straight past it.
     */
    public function test_the_phone_guard_sees_through_arabic_numerals_and_stray_spaces(): void
    {
        Customer::factory()->create(['phone' => '01001234567']);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'ZZZ Duplicate', 'phone' => ' ٠١٠٠١٢٣٤٥٦٧ '])
            ->call('create')
            ->assertHasFormErrors(['phone']);
    }

    public function test_editing_a_customer_may_keep_its_own_contact_details(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'client@example.com',
            'phone' => '01009998877',
        ]);

        Livewire::test(EditCustomer::class, ['record' => $customer->getKey()])
            ->fillForm([
                'name' => 'Renamed but same contact',
                'email' => 'client@example.com',
                'phone' => '01009998877',
            ])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_a_soft_deleted_customer_does_not_hold_its_contact_details_hostage(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'archived@example.com',
            'phone' => '01005554443',
        ]);
        $customer->delete();

        Livewire::test(CreateCustomer::class)
            ->fillForm([
                'name' => 'New client, recycled details',
                'email' => 'archived@example.com',
                'phone' => '01005554443',
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_suppliers_are_guarded_the_same_way(): void
    {
        Supplier::factory()->create(['email' => 'vendor@example.com']);

        Livewire::test(CreateSupplier::class)
            ->fillForm(['name' => 'ZZZ Duplicate vendor', 'email' => 'vendor@example.com'])
            ->call('create')
            ->assertHasFormErrors(['email']);
    }

    /**
     * A customer and a supplier are separate files — sharing a contact detail
     * across the two tables is legitimate (the same company can be both).
     */
    public function test_a_supplier_may_share_contact_details_with_a_customer(): void
    {
        Customer::factory()->create([
            'email' => 'both@example.com',
            'phone' => '01007776665',
        ]);

        Livewire::test(CreateSupplier::class)
            ->fillForm([
                'name' => 'Also a supplier',
                'email' => 'both@example.com',
                'phone' => '01007776665',
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    /**
     * §8 also asked whether the 1%-exemption toggle defaults ON. It does not,
     * and must not: switching it on suppresses the withholding deduction on
     * every purchase order for that supplier.
     */
    public function test_profit_tax_exemption_is_off_by_default(): void
    {
        Livewire::test(CreateSupplier::class)
            ->assertFormSet(['profit_tax_exempt' => false]);

        $this->assertFalse((bool) Supplier::factory()->create()->profit_tax_exempt);
    }
}
