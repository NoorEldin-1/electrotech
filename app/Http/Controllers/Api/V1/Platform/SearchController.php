<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Customer;
use App\Models\DeliveryVoucher;
use App\Models\Item;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * @group 42. Global search
 *
 * One box, seven record types: operations, items, customers, suppliers,
 * purchase orders, manufacturing orders and delivery vouchers.
 *
 * Two decisions shape this endpoint.
 *
 * **It respects the caller's permissions per type.** A user who cannot view
 * suppliers gets no supplier results — not an empty list they might read as
 * "no such supplier", but a response whose `types` array does not include
 * suppliers at all, so a client can say what was searched. A global search
 * that ignored per-type permissions would be the easiest way in the platform
 * to confirm that a record exists without being allowed to read it.
 *
 * **It is deliberately shallow.** Each type returns at most `limit` rows
 * (five by default, twenty at most) with just enough to render a result line
 * and navigate to the record. It is a jump-to, not a report: anything that
 * needs filtering, sorting or paging belongs on that type's own index, which
 * is where the query language lives.
 *
 * The term is matched against the identifiers people actually type — a code, a
 * number, a name — never against notes or descriptions, which would bury the
 * exact match everyone is looking for under prose that merely mentions it.
 */
class SearchController extends ApiController
{
    /**
     * Search across the platform
     *
     * @authenticated
     *
     * @queryParam q string required At least two characters. Example: 2026-14
     * @queryParam limit integer Rows per type, max 20. Defaults to 5. Example: 5
     * @queryParam types string Comma-separated subset to search. Defaults to everything you may see. Example: projects,items
     *
     * @response 200 scenario="Success" {"data":{"query":"2026-14","types":["projects","items","customers"],"results":{"projects":[{"id":4,"type":"project","label":"2026-14","description":"Main distribution board — Cairo tower"}],"items":[],"customers":[]}},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Term too short" {"error":{"code":"validation_failed","message":"The given data was invalid.","details":{"q":["Search for at least 2 characters."]}},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function index(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            throw ValidationException::withMessages([
                'q' => ['Search for at least 2 characters.'],
            ]);
        }

        $limit = min(max((int) $request->query('limit', '5'), 1), 20);

        $requested = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $request->query('types', '')),
        )));

        $searchable = $this->searchableTypes($term, $limit);

        // An unknown type name is a 422 that lists what exists, never a silent
        // no-op — the same rule ApiQuery applies to filters, for the same
        // reason: a client should not ship a search that never worked.
        if ($requested !== []) {
            $unknown = array_diff($requested, array_keys($this->allTypes()));

            if ($unknown !== []) {
                throw ValidationException::withMessages([
                    'types' => [sprintf(
                        'Unknown type(s): %s. Allowed: %s.',
                        implode(', ', $unknown),
                        implode(', ', array_keys($this->allTypes())),
                    )],
                ]);
            }

            $searchable = array_intersect_key($searchable, array_flip($requested));
        }

        $results = [];

        foreach ($searchable as $type => $resolve) {
            $results[$type] = $resolve();
        }

        return $this->respond([
            'query' => $term,

            // What was ACTUALLY searched, after permissions. A client can then
            // say so rather than presenting a permission gap as an absence.
            'types' => array_keys($results),

            'results' => $results,
        ]);
    }

    /**
     * Every type this endpoint knows, mapped to the permission that gates it.
     *
     * @return array<string, string>
     */
    private function allTypes(): array
    {
        return [
            'projects' => 'projects.view',
            'items' => 'items.view',
            'customers' => 'customers.view',
            'suppliers' => 'suppliers.view',
            'purchase_orders' => 'purchase_orders.view',
            'work_orders' => 'work_orders.view',
            'delivery_vouchers' => 'delivery_vouchers.view',
        ];
    }

    /**
     * The subset of types this caller may search, each as a closure that runs
     * its query only if it is actually reached.
     *
     * @return array<string, callable(): array<int, array<string, mixed>>>
     */
    private function searchableTypes(string $term, int $limit): array
    {
        $user = request()->user();
        $available = [];

        foreach ($this->allTypes() as $type => $permission) {
            if (! $user?->can($permission)) {
                continue;
            }

            $available[$type] = match ($type) {
                'projects' => fn () => $this->hits(
                    Project::query(), ['code', 'name', 'client_name'], $term, $limit,
                    fn (Project $p) => ['label' => $p->code, 'description' => $p->name],
                ),
                'items' => fn () => $this->hits(
                    Item::query(), ['sku', 'name'], $term, $limit,
                    fn (Item $i) => ['label' => $i->sku, 'description' => $i->name],
                ),
                'customers' => fn () => $this->hits(
                    Customer::query(), ['name', 'phone', 'email'], $term, $limit,
                    fn (Customer $c) => ['label' => $c->name, 'description' => $c->phone],
                ),
                'suppliers' => fn () => $this->hits(
                    Supplier::query(), ['name', 'phone', 'email'], $term, $limit,
                    fn (Supplier $s) => ['label' => $s->name, 'description' => $s->phone],
                ),
                'purchase_orders' => fn () => $this->hits(
                    PurchaseOrder::query(), ['po_number', 'supplier_name'], $term, $limit,
                    fn (PurchaseOrder $o) => ['label' => $o->po_number, 'description' => $o->supplier_name],
                ),
                'work_orders' => fn () => $this->hits(
                    WorkOrder::query(), ['wo_number', 'title'], $term, $limit,
                    fn (WorkOrder $w) => ['label' => $w->wo_number, 'description' => $w->title],
                ),
                'delivery_vouchers' => fn () => $this->hits(
                    DeliveryVoucher::query(), ['voucher_number', 'supply_order_number'], $term, $limit,
                    fn (DeliveryVoucher $d) => ['label' => $d->voucher_number, 'description' => $d->supply_order_number],
                ),
            };
        }

        return $available;
    }

    /**
     * @param  list<string>  $columns
     * @param  callable(\Illuminate\Database\Eloquent\Model): array<string, mixed>  $present
     * @return array<int, array<string, mixed>>
     */
    private function hits(Builder $query, array $columns, string $term, int $limit, callable $present): array
    {
        // `%`, `_` and `\` are LIKE wildcards: without escaping, a search for
        // "100%" would match everything beginning with "100".
        $escaped = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        return $query
            ->where(function (Builder $inner) use ($columns, $escaped): void {
                foreach ($columns as $column) {
                    $inner->orWhere($column, 'like', $escaped);
                }
            })
            ->limit($limit)
            ->get()
            ->map(fn ($model) => array_merge(
                ['id' => $model->getKey(), 'type' => \Illuminate\Support\Str::snake(class_basename($model))],
                $present($model),
            ))
            ->values()
            ->all();
    }
}
