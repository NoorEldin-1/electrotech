<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

/**
 * The E2E seeders create accounts with a known password and fixed data, and
 * the chain seeder posts journal entries: they exist for the throwaway sqlite
 * database of the CI browser run (and local test databases) and nowhere else.
 *
 * "Not production" is too weak a test — a staging copy running with
 * APP_ENV=staging against MySQL would pass it. This allows only a local or
 * testing environment on a sqlite connection.
 */
trait GuardsE2eEnvironment
{
    protected function guardE2eEnvironment(): void
    {
        abort_unless(
            app()->environment(['local', 'testing']) && config('database.default') === 'sqlite',
            500,
            sprintf(
                '%s only runs in a local or testing environment on a sqlite database (APP_ENV=%s, DB_CONNECTION=%s).',
                static::class,
                (string) app()->environment(),
                (string) config('database.default'),
            ),
        );
    }
}
