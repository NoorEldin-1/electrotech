<?php

declare(strict_types=1);

namespace App\Sync;

use App\Models\DeviceToken;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Registry of named pull-scope closures.
 *
 * Resource models (BoM, BoMItem, WorkOrder, etc.) call into scopes by
 * name (the string from config/sync.php → pull_scope). The closures
 * themselves live in App\Providers\SyncServiceProvider so they have
 * access to the application context (Eloquent relations, policies)
 * without leaking that surface into config files.
 *
 * Each scope: (Builder, DeviceToken) -> Builder. It must NOT throw —
 * a failing scope should narrow to no results, not break the whole
 * pull request.
 */
final class SyncScopeRegistry
{
    /** @var array<string, Closure(Builder, DeviceToken): Builder> */
    private array $scopes = [];

    public function register(string $name, Closure $scope): void
    {
        $this->scopes[$name] = $scope;
    }

    public function resolve(string $name): ?Closure
    {
        return $this->scopes[$name] ?? null;
    }
}
