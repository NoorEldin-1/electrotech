<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\DeviceToken;
use App\Models\WorkOrder;
use App\Sync\SyncScopeRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the offline-first sync subsystem into the application:
 *
 *   - Binds SyncScopeRegistry as a singleton (per-user authority scopes).
 *   - Registers the canonical scope closures for each model.
 *
 * Each scope is keyed by the name used in config/sync.php → pull_scope.
 * Returning the unmodified builder is acceptable for models that have
 * no per-user constraint (catalog data); the closures here are the ones
 * that actually constrain the pull set.
 *
 * The closures themselves intentionally do not throw. A user without
 * permission to see *anything* in a model gets back zero rows, which
 * is correct behaviour for the sync endpoint.
 */
final class SyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SyncScopeRegistry::class);
    }

    public function boot(): void
    {
        /** @var SyncScopeRegistry $registry */
        $registry = $this->app->make(SyncScopeRegistry::class);

        // Projects: an operator sees projects they have a WO in.
        // Falls back to "no projects" rather than "all projects" — leaking
        // sales pipeline data to a roving tablet would be a privacy
        // regression.
        $registry->register('project.scope', function (Builder $query, DeviceToken $token): Builder {
            $userId = $token->user_id;
            return $query->whereIn('id', function ($sub) use ($userId) {
                $sub->select('project_id')
                    ->from('work_orders')
                    ->where(function ($q) use ($userId) {
                        $q->where('assigned_to', $userId)->orWhere('created_by', $userId);
                    });
            });
        });

        // BoMs: scoped to projects the operator can see.
        $registry->register('bom.scope', function (Builder $query, DeviceToken $token): Builder {
            $userId = $token->user_id;
            return $query->whereIn('project_id', function ($sub) use ($userId) {
                $sub->select('project_id')
                    ->from('work_orders')
                    ->where(function ($q) use ($userId) {
                        $q->where('assigned_to', $userId)->orWhere('created_by', $userId);
                    });
            });
        });

        // BoM items: same scoping but indirect through bom_id.
        $registry->register('bom_item.scope', function (Builder $query, DeviceToken $token): Builder {
            $userId = $token->user_id;
            return $query->whereIn('bom_id', function ($sub) use ($userId) {
                $sub->select('boms.id')
                    ->from('boms')
                    ->join('work_orders', 'work_orders.project_id', '=', 'boms.project_id')
                    ->where(function ($q) use ($userId) {
                        $q->where('work_orders.assigned_to', $userId)
                          ->orWhere('work_orders.created_by', $userId);
                    });
            });
        });

        // Work orders: assigned-to or created-by the operator. The actual
        // QA approver is allowed to see WOs in QA review even if they
        // didn't create them — that case is added with orWhere on the
        // qa_approved_by trail (the user may not be set yet, so we use
        // the role-based check as a second clause).
        $registry->register('work_order.scope', function (Builder $query, DeviceToken $token): Builder {
            $userId = $token->user_id;
            $user = $token->user;

            return $query->where(function (Builder $q) use ($userId, $user) {
                $q->where('assigned_to', $userId)
                  ->orWhere('created_by', $userId);

                // QA approvers can see any WO awaiting QA — they need
                // to see them to approve them, and the assignment is on
                // the operator, not the approver.
                if ($user && method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['qa', 'manager', 'super-admin'])) {
                    $q->orWhere('status', 'qa_review');
                }
            });
        });

        // Inventory transactions: an operator sees the transactions they
        // performed AND any transactions referencing a WO they own. Other
        // operators' raw consumption isn't useful and would balloon the
        // sync set.
        $registry->register('inventory_transaction.scope', function (Builder $query, DeviceToken $token): Builder {
            $userId = $token->user_id;
            return $query->where(function ($q) use ($userId) {
                $q->where('performed_by', $userId)
                  ->orWhere(function ($qq) use ($userId) {
                      $qq->where('reference_type', WorkOrder::class)
                         ->whereIn('reference_id', function ($sub) use ($userId) {
                             $sub->select('id')
                                 ->from('work_orders')
                                 ->where(function ($w) use ($userId) {
                                     $w->where('assigned_to', $userId)->orWhere('created_by', $userId);
                                 });
                         });
                  });
            });
        });
    }
}
