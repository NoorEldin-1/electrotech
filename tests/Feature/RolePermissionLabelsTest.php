<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The roles screen labels every permission through
 * `resources.roles.permissions.<group>.<action>` and every section through
 * `resources.roles.groups.<group>`. A permission added to the seeder without
 * those keys renders as a raw translation key on screen — this test fails
 * instead, in both locales, for every permission the seeder creates.
 */
class RolePermissionLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_seeded_permission_is_labelled_in_both_locales(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $names = Permission::query()->orderBy('name')->pluck('name');

        $this->assertNotEmpty($names, 'The seeder should create permissions.');

        $missing = [];

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            foreach ($names as $name) {
                $group = str_contains($name, '.') ? explode('.', $name)[0] : $name;

                foreach ([
                    'resources.roles.groups.' . $group,
                    'resources.roles.permissions.' . $name,
                ] as $key) {
                    if (__($key) === $key) {
                        $missing[] = "[{$locale}] {$key}";
                    }
                }
            }
        }

        app()->setLocale('en');

        $this->assertSame([], array_values(array_unique($missing)), 'Untranslated permission labels: ' . implode(', ', array_unique($missing)));
    }
}
