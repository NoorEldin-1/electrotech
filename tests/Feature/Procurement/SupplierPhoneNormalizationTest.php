<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Filament\Resources\SupplierResource\Pages\CreateSupplier;
use App\Filament\Support\PhoneInput;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression for the المشتريات feedback: a phone typed on an Arabic keyboard
 * (Arabic-Indic numerals) or with a stray leading space was rejected with the
 * raw "validation.regex" error, because Filament's ->tel() attaches a strict
 * ASCII-only regex. App\Filament\Support\PhoneInput drops that regex, accepts
 * the input, and normalises it to ASCII on save.
 */
class SupplierPhoneNormalizationTest extends TestCase
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

    public function test_supplier_accepts_arabic_numeral_phone_and_stores_it_as_ascii(): void
    {
        Livewire::test(CreateSupplier::class)
            ->fillForm([
                'name' => 'ثري إم للنظم الكهربائية',
                'phone' => '٠١٢٠٢٢٠٠٧٥٣', // Arabic-Indic numerals
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('suppliers', [
            'name' => 'ثري إم للنظم الكهربائية',
            'phone' => '01202200753',
        ]);
    }

    public function test_supplier_accepts_phone_with_leading_space_and_trims_it(): void
    {
        Livewire::test(CreateSupplier::class)
            ->fillForm([
                'name' => 'Acme Supplies',
                'phone' => '  012 0220 0753 ',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('suppliers', [
            'name' => 'Acme Supplies',
            'phone' => '012 0220 0753',
        ]);
    }

    public function test_normalize_converts_arabic_and_persian_numerals(): void
    {
        $this->assertSame('01202200753', PhoneInput::normalize('٠١٢٠٢٢٠٠٧٥٣'));
        $this->assertSame('01202200753', PhoneInput::normalize('۰۱۲۰۲۲۰۰۷۵۳'));
        $this->assertSame('012 0220', PhoneInput::normalize('  012 0220  '));
        $this->assertNull(PhoneInput::normalize(null));
    }
}
