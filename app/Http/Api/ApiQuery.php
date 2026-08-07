<?php

declare(strict_types=1);

namespace App\Http\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Whitelisted filtering / sorting / eager-loading / pagination for index
 * endpoints.
 *
 * Deliberately in-house rather than a package. Two properties matter more than
 * saving the code:
 *
 *   1. Everything is opt-in. A relation the endpoint did not whitelist cannot
 *      be eager-loaded, so a client cannot turn a fast list into a slow one by
 *      guessing relation names.
 *   2. An unknown key is a 422 that names the allowed keys — never a silent
 *      no-op. Silent ignoring is how a client ships a filter that never worked
 *      and nobody notices for months.
 *
 * Usage:
 *
 *     ApiQuery::for(Project::query(), $request)
 *         ->allowFilters([
 *             'status'   => ApiQuery::exact('status'),
 *             'customer' => ApiQuery::exact('customer_id'),
 *             'created'  => ApiQuery::dateBetween('created_at'),
 *         ])
 *         ->allowSearch(['name', 'code', 'client_name'])
 *         ->allowSorts(['created_at', 'name', 'code'])
 *         ->allowIncludes(['customer', 'latestOffer'])
 *         ->defaultSort('-created_at')
 *         ->paginate();
 */
final class ApiQuery
{
    /** @var array<string, callable(Builder, mixed): void> */
    private array $filters = [];

    /** @var list<string> */
    private array $searchable = [];

    /** @var list<string> */
    private array $sortable = [];

    /** @var list<string> */
    private array $includable = [];

    private ?string $defaultSort = null;

    /**
     * Column used by the `updated_after` cache-refresh filter. See
     * API_Development_Plan.md §1.3 — this is a plain filter, not a sync
     * protocol.
     */
    private string $updatedAtColumn = 'updated_at';

    private function __construct(
        private readonly Builder $query,
        private readonly Request $request,
    ) {}

    public static function for(Builder $query, Request $request): self
    {
        return new self($query, $request);
    }

    // ---------------------------------------------------------------- config

    /**
     * @param  array<string, callable(Builder, mixed): void>  $filters
     */
    public function allowFilters(array $filters): self
    {
        $this->filters = $filters;

        return $this;
    }

    /**
     * @param  list<string>  $columns
     */
    public function allowSearch(array $columns): self
    {
        $this->searchable = $columns;

        return $this;
    }

    /**
     * @param  list<string>  $columns
     */
    public function allowSorts(array $columns): self
    {
        $this->sortable = $columns;

        return $this;
    }

    /**
     * @param  list<string>  $relations
     */
    public function allowIncludes(array $relations): self
    {
        $this->includable = $relations;

        return $this;
    }

    /**
     * @param  string  $sort  e.g. '-created_at'
     */
    public function defaultSort(string $sort): self
    {
        $this->defaultSort = $sort;

        return $this;
    }

    public function updatedAtColumn(string $column): self
    {
        $this->updatedAtColumn = $column;

        return $this;
    }

    // ----------------------------------------------------------- filter kinds

    /**
     * `?filter[status]=in_progress` or `?filter[status]=tender,in_hand`
     * (comma = OR).
     */
    public static function exact(string $column): callable
    {
        return function (Builder $query, mixed $value) use ($column): void {
            $values = is_array($value) ? $value : explode(',', (string) $value);
            $values = array_values(array_filter(array_map('trim', $values), fn ($v) => $v !== ''));

            if ($values === []) {
                return;
            }

            count($values) === 1
                ? $query->where($column, $values[0])
                : $query->whereIn($column, $values);
        };
    }

    /**
     * `?filter[name]=panel` — case-insensitive contains.
     */
    public static function partial(string $column): callable
    {
        return function (Builder $query, mixed $value) use ($column): void {
            $value = trim((string) $value);

            if ($value === '') {
                return;
            }

            $query->where($column, 'like', '%'.self::escapeLike($value).'%');
        };
    }

    /**
     * `?filter[approved]=true` / `false`.
     */
    public static function boolean(string $column): callable
    {
        return function (Builder $query, mixed $value) use ($column): void {
            $bool = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            if ($bool === null) {
                throw ValidationException::withMessages([
                    "filter.{$column}" => ['Expected a boolean value (true/false).'],
                ]);
            }

            $query->where($column, $bool);
        };
    }

    /**
     * `?filter[created]=2026-01-01,2026-06-30` — inclusive range. Either side
     * may be omitted: `2026-01-01,` is "from", `,2026-06-30` is "until".
     */
    public static function dateBetween(string $column): callable
    {
        return function (Builder $query, mixed $value) use ($column): void {
            $parts = is_array($value) ? $value : explode(',', (string) $value);
            $from = trim((string) ($parts[0] ?? ''));
            $until = trim((string) ($parts[1] ?? ''));

            if ($from !== '') {
                $query->whereDate($column, '>=', $from);
            }

            if ($until !== '') {
                $query->whereDate($column, '<=', $until);
            }
        };
    }

    /**
     * `?filter[has_offer]=true` — existence of a relation.
     */
    public static function hasRelation(string $relation): callable
    {
        return function (Builder $query, mixed $value) use ($relation): void {
            $bool = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;

            $bool
                ? $query->whereHas($relation)
                : $query->whereDoesntHave($relation);
        };
    }

    // ------------------------------------------------------------- execution

    public function paginate(): LengthAwarePaginator
    {
        $this->applyIncludes();
        $this->applyFilters();
        $this->applySearch();
        $this->applyUpdatedAfter();
        $this->applySorts();

        return $this->query
            ->paginate($this->perPage())
            ->appends($this->request->query());
    }

    /**
     * Escape hatch for endpoints that need the builder back (e.g. to feed a
     * report service) without paginating.
     */
    public function builder(): Builder
    {
        $this->applyIncludes();
        $this->applyFilters();
        $this->applySearch();
        $this->applyUpdatedAfter();
        $this->applySorts();

        return $this->query;
    }

    private function perPage(): int
    {
        $default = (int) config('api.pagination.default_per_page');
        $max = (int) config('api.pagination.max_per_page');

        $raw = $this->request->query('per_page');

        if ($raw === null || $raw === '') {
            return $default;
        }

        if (! is_numeric($raw) || (int) $raw < 1) {
            throw ValidationException::withMessages([
                'per_page' => ['per_page must be a positive integer.'],
            ]);
        }

        $perPage = (int) $raw;

        // A hard ceiling, not a clamp — see config/api.php.
        if ($perPage > $max) {
            throw ValidationException::withMessages([
                'per_page' => ["per_page may not be greater than {$max}."],
            ]);
        }

        return $perPage;
    }

    private function applyFilters(): void
    {
        $requested = $this->request->query('filter', []);

        if ($requested === [] || $requested === null) {
            return;
        }

        if (! is_array($requested)) {
            throw ValidationException::withMessages([
                'filter' => ['filter must be given as filter[key]=value.'],
            ]);
        }

        $unknown = array_diff(array_keys($requested), array_keys($this->filters));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'filter' => [sprintf(
                    'Unknown filter(s): %s. Allowed: %s.',
                    implode(', ', $unknown),
                    implode(', ', array_keys($this->filters)) ?: 'none',
                )],
            ]);
        }

        foreach ($requested as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            ($this->filters[$key])($this->query, $value);
        }
    }

    private function applySearch(): void
    {
        $term = trim((string) $this->request->query('search', ''));

        if ($term === '' || $this->searchable === []) {
            return;
        }

        $escaped = '%'.self::escapeLike($term).'%';

        $this->query->where(function (Builder $query) use ($escaped): void {
            foreach ($this->searchable as $column) {
                $query->orWhere($column, 'like', $escaped);
            }
        });
    }

    /**
     * Plain `updated_at >` filter so a client can refresh a local cache without
     * re-downloading everything. NOT a sync protocol: the server keeps no
     * cursor and makes no delivery guarantee (API_Development_Plan.md §1.3).
     */
    private function applyUpdatedAfter(): void
    {
        $since = trim((string) $this->request->query('updated_after', ''));

        if ($since === '') {
            return;
        }

        try {
            $timestamp = \Carbon\Carbon::parse($since);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'updated_after' => ['updated_after must be a valid ISO-8601 timestamp.'],
            ]);
        }

        $this->query->where($this->updatedAtColumn, '>', $timestamp);
    }

    private function applySorts(): void
    {
        $raw = trim((string) $this->request->query('sort', ''));

        if ($raw === '') {
            if ($this->defaultSort !== null) {
                $this->applySortToken($this->defaultSort);
            }

            return;
        }

        foreach (explode(',', $raw) as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            $column = ltrim($token, '-');

            if (! in_array($column, $this->sortable, true)) {
                throw ValidationException::withMessages([
                    'sort' => [sprintf(
                        'Cannot sort by "%s". Allowed: %s.',
                        $column,
                        implode(', ', $this->sortable) ?: 'none',
                    )],
                ]);
            }

            $this->applySortToken($token);
        }
    }

    private function applySortToken(string $token): void
    {
        $descending = str_starts_with($token, '-');

        $this->query->orderBy(ltrim($token, '-'), $descending ? 'desc' : 'asc');
    }

    private function applyIncludes(): void
    {
        $raw = trim((string) $this->request->query('include', ''));

        if ($raw === '') {
            return;
        }

        $requested = array_values(array_filter(array_map('trim', explode(',', $raw))));
        $unknown = array_diff($requested, $this->includable);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'include' => [sprintf(
                    'Unknown include(s): %s. Allowed: %s.',
                    implode(', ', $unknown),
                    implode(', ', $this->includable) ?: 'none',
                )],
            ]);
        }

        if ($requested !== []) {
            $this->query->with($requested);
        }
    }

    /**
     * `%`, `_` and `\` are LIKE wildcards. Without escaping, a search for
     * "100%" would match everything starting with "100".
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
