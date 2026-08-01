<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * E2E report §5.3 — "Inconsistent post-save redirect across modules".
 *
 * Customers and Suppliers landed on the record's Edit page after a create
 * while Items and Projects went back to the list, because only 5 of 24
 * resources overrode Filament's default. The platform rule is now "save
 * returns you to the list", and this test is what keeps a new resource from
 * silently reintroducing the split.
 */
class PostSaveRedirectConsistencyTest extends TestCase
{
    /**
     * @return array<int, class-string>
     */
    private function recordPageClasses(): array
    {
        $classes = [];

        $finder = (new Finder())
            ->files()
            ->in(app_path('Filament/Resources'))
            ->name(['Create*.php', 'Edit*.php']);

        /** @var SplFileInfo $file */
        foreach ($finder as $file) {
            $relative = Str::of($file->getRealPath())
                ->after(app_path() . DIRECTORY_SEPARATOR)
                ->replace(['/', '\\'], '\\')
                ->beforeLast('.php');

            $class = 'App\\' . $relative;

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            if (! $reflection->isSubclassOf(\Filament\Resources\Pages\CreateRecord::class)
                && ! $reflection->isSubclassOf(\Filament\Resources\Pages\EditRecord::class)) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }

    public function test_the_suite_actually_found_the_record_pages(): void
    {
        // Guards against the finder silently matching nothing and turning the
        // assertion below into a no-op.
        $this->assertGreaterThan(30, count($this->recordPageClasses()));
    }

    public function test_every_create_and_edit_page_redirects_to_its_resource_list(): void
    {
        $offenders = [];

        foreach ($this->recordPageClasses() as $class) {
            $method = new ReflectionMethod($class, 'getRedirectUrl');

            // Declared on the page itself, not inherited from Filament.
            if ($method->getDeclaringClass()->getName() !== $class) {
                $offenders[] = $class . ' — does not override getRedirectUrl()';

                continue;
            }

            $body = file_get_contents($method->getFileName());

            if (! str_contains($body, "getResource()::getUrl('index')")) {
                $offenders[] = $class . ' — redirects somewhere other than the list';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Every Create/Edit page must return to its resource list after saving:\n"
            . implode("\n", $offenders)
        );
    }
}
