<?php

namespace Tests\Feature\Sales;

use App\Enums\ConductorType;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slide 4: "نوع الفصل" becomes "اسم الموصل" — a fixed list of
 * copper / aluminium / bi-metal.
 */
class ConductorTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_there_are_exactly_three_conductor_options(): void
    {
        $this->assertSame(
            ['copper', 'aluminum', 'bi_metal'],
            array_map(fn (ConductorType $c) => $c->value, ConductorType::cases()),
        );
    }

    public function test_section_type_stores_the_conductor_value(): void
    {
        $project = Project::factory()->create(['section_type' => ConductorType::BiMetal->value]);

        $this->assertSame('bi_metal', $project->fresh()->section_type);
    }

    public function test_conductor_labels_resolve_in_both_locales(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            foreach (ConductorType::cases() as $case) {
                $key = 'resources.enums.conductor_type.'.$case->value;
                $this->assertNotSame($key, __($key), "Missing $locale key: $key");
            }
        }
    }

    public function test_arabic_conductor_labels(): void
    {
        app()->setLocale('ar');

        $this->assertSame('نحاس', ConductorType::Copper->getLabel());
        $this->assertSame('ألومنيوم', ConductorType::Aluminum->getLabel());
        $this->assertSame('باي ميتال', ConductorType::BiMetal->getLabel());
    }
}
