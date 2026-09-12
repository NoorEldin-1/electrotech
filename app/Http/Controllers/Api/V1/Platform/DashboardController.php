<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\DeliveryVoucherStatus;
use App\Enums\ProjectStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\WorkOrderStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\DeliveryVoucher;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * @group 39. Dashboard
 *
 * The five counts the platform opens on: active operations, deliveries waiting
 * for a signature, purchase orders out with suppliers, orders on the shop
 * floor, and items below their minimum.
 *
 * These read the **same cache keys the admin panel's own dashboard reads** —
 * `dashboard:active_projects` and its four siblings — rather than recomputing
 * the numbers. Two consequences worth knowing:
 *
 *  - a phone and a desktop opened side by side show the same figures, because
 *    they are the same figures, not two independent counts that happen to
 *    agree most of the time;
 *  - the numbers are invalidated by the model observers the moment the
 *    underlying state changes, with a five-minute ceiling as a safety net in
 *    case a write ever bypasses model events.
 *
 * `low_stock` is the expensive one — it aggregates availability per item
 * across every warehouse — which is why the whole endpoint sits on the reports
 * rate limiter rather than the read one.
 */
class DashboardController extends ApiController
{
    /**
     * Dashboard counts
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {"data":{"active_projects":7,"pending_deliveries":2,"pending_purchase_orders":4,"active_work_orders":3,"low_stock_items":11,"generated_at":"2026-09-12T18:30:00+00:00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function index(): JsonResponse
    {
        $this->authorizePermission('dashboard.view');

        return $this->respond([
            'active_projects' => $this->cached(
                'dashboard:active_projects',
                fn (): int => Project::query()->where('status', ProjectStatus::InProgress)->count(),
            ),

            'pending_deliveries' => $this->cached(
                'dashboard:pending_deliveries',
                fn (): int => DeliveryVoucher::query()
                    ->whereIn('status', [
                        DeliveryVoucherStatus::Draft,
                        DeliveryVoucherStatus::PendingApproval,
                    ])
                    ->count(),
            ),

            'pending_purchase_orders' => $this->cached(
                'dashboard:pending_pos',
                fn (): int => PurchaseOrder::query()
                    ->where('status', PurchaseOrderStatus::Submitted)
                    ->count(),
            ),

            'active_work_orders' => $this->cached(
                'dashboard:active_wos',
                fn (): int => WorkOrder::query()
                    ->where('status', WorkOrderStatus::InProgress)
                    ->count(),
            ),

            // Items whose availability (on hand minus held), summed across
            // every warehouse, has fallen below their minimum. Aggregated per
            // item because one item legitimately holds rows in several stores.
            'low_stock_items' => $this->cached(
                'dashboard:low_stock_count',
                fn (): int => DB::table('items')
                    ->leftJoin('inventories', 'inventories.item_id', '=', 'items.id')
                    ->whereNull('items.deleted_at')
                    ->groupBy('items.id', 'items.minimum_stock')
                    ->havingRaw('COALESCE(SUM(inventories.on_hand_quantity - inventories.on_hold_quantity), 0) < items.minimum_stock')
                    ->select('items.id')
                    ->get()
                    ->count(),
            ),

            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Read a panel dashboard cache key, computing it the same way the widget
     * does on a miss.
     *
     * The key and the closure must stay identical to the widget's — sharing
     * the key while computing it differently would be worse than not sharing
     * it at all, because the two would disagree only intermittently.
     *
     * @param  callable(): int  $compute
     */
    private function cached(string $key, callable $compute): int
    {
        return (int) Cache::remember($key, now()->addMinutes(5), $compute);
    }
}
