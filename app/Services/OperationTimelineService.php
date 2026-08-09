<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Enums\VoucherStatus;
use App\Models\DeliveryVoucher;
use App\Models\Installation;
use App\Models\IssueVoucher;
use App\Models\Project;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * الخط الزمنى للعملية — ماليات.pptx سلايد 7:
 *
 *   «يجب عمل مراكز تكلفة + خط زمنى لكل مشروع بالعميل (العملية في المصنع، او
 *    العملية تم تركيبها، او العملية مازلت فى المكتب الفنى لانشاء امر التشغيل،
 *    وهكذا)»
 *
 * The cost centre half already exists — the operation IS the cost centre. This
 * service adds the other half: where the operation stands, and when it got
 * there.
 *
 * Deliberately DERIVED, with no new table and no new column. Every stage is
 * read from a record the operation already produces, so the timeline can never
 * drift out of step with reality the way a manually maintained "current stage"
 * field always eventually does. Add a work order and the timeline moves by
 * itself.
 */
class OperationTimelineService
{
    /**
     * The full timeline of one operation, oldest stage first. Every stage is
     * returned whether reached or not, so the screen shows what is still ahead
     * as well as what is done.
     *
     * @return Collection<int, array{key: string, label: string, at: ?Carbon, reached: bool, detail: ?string, days_from_start: ?int}>
     */
    public function for(Project $project): Collection
    {
        $stages = collect($this->stages($project))
            ->map(fn (array $stage): array => $stage + [
                'reached' => $stage['at'] !== null,
                'detail' => $stage['detail'] ?? null,
            ]);

        $start = $stages->firstWhere('at', '!=', null)['at'] ?? null;

        return $stages->map(function (array $stage) use ($start): array {
            $stage['days_from_start'] = ($start && $stage['at'])
                ? (int) $start->diffInDays($stage['at'])
                : null;

            return $stage;
        })->values();
    }

    /**
     * The single label سلايد 7 asks for — the furthest stage the operation has
     * actually reached ("العملية في المصنع", "العملية تم تركيبها"…).
     *
     * @return array{key: string, label: string, at: ?Carbon}
     */
    public function currentStage(Project $project): array
    {
        $reached = $this->for($project)->filter(fn (array $s): bool => $s['reached']);

        if ($reached->isEmpty()) {
            return ['key' => 'not_started', 'label' => __('resources.operation_timeline.stages.not_started'), 'at' => null];
        }

        $last = $reached->last();

        return ['key' => $last['key'], 'label' => $last['label'], 'at' => $last['at']];
    }

    /**
     * Raw stage definitions in chronological order. `at` is null for a stage
     * the operation has not reached.
     *
     * @return array<int, array{key: string, label: string, at: ?Carbon, detail?: ?string}>
     */
    private function stages(Project $project): array
    {
        $workOrders = $project->workOrders()->get();
        $issued = $this->firstIssueVoucherDate($workOrders);
        $delivery = $this->firstDeliveryDate($project);
        $installation = Installation::query()->where('project_id', $project->getKey())->get();

        return [
            [
                'key' => 'sales',
                'label' => __('resources.operation_timeline.stages.sales'),
                'at' => $this->toCarbon($project->created_at),
                'detail' => $project->customer?->name ?? $project->client_name,
            ],
            [
                'key' => 'activated',
                'label' => __('resources.operation_timeline.stages.activated'),
                'at' => $this->activationDate($project),
            ],
            [
                // "العملية مازلت فى المكتب الفنى لانشاء امر التشغيل"
                'key' => 'technical_office',
                'label' => __('resources.operation_timeline.stages.technical_office'),
                'at' => $this->toCarbon($workOrders->min('created_at')),
                'detail' => $workOrders->isNotEmpty()
                    ? __('resources.operation_timeline.details.work_orders', ['count' => $workOrders->count()])
                    : null,
            ],
            [
                'key' => 'order_approved',
                'label' => __('resources.operation_timeline.stages.order_approved'),
                'at' => $this->toCarbon($workOrders->min('order_approved_at')),
            ],
            [
                // "العملية في المصنع" — material actually left the store for it.
                'key' => 'in_factory',
                'label' => __('resources.operation_timeline.stages.in_factory'),
                'at' => $issued,
            ],
            [
                'key' => 'manufacturing_finished',
                'label' => __('resources.operation_timeline.stages.manufacturing_finished'),
                'at' => $this->allReached($workOrders, 'manufacturing_finished_at'),
            ],
            [
                'key' => 'qa_approved',
                'label' => __('resources.operation_timeline.stages.qa_approved'),
                'at' => $this->allReached($workOrders, 'qa_approved_at'),
            ],
            [
                'key' => 'delivered',
                'label' => __('resources.operation_timeline.stages.delivered'),
                'at' => $delivery,
            ],
            [
                // "العملية تم تركيبها"
                'key' => 'installed',
                'label' => __('resources.operation_timeline.stages.installed'),
                'at' => $this->toCarbon($installation->max('completed_at')),
            ],
            [
                'key' => 'completed',
                'label' => __('resources.operation_timeline.stages.completed'),
                'at' => $project->status === ProjectStatus::Completed
                    ? ($this->toCarbon($project->end_date) ?? $this->toCarbon($project->updated_at))
                    : null,
            ],
        ];
    }

    /**
     * When the operation became active. `start_date` is the intended date the
     * sales team entered; falling back to it keeps the timeline useful for
     * operations activated before the status was tracked.
     */
    private function activationDate(Project $project): ?Carbon
    {
        if (in_array($project->status, [ProjectStatus::Tender, ProjectStatus::InHand, ProjectStatus::Lost], true)) {
            return null;
        }

        return $this->toCarbon($project->start_date) ?? $this->toCarbon($project->created_at);
    }

    /** First posted issue voucher across the operation's work orders. */
    private function firstIssueVoucherDate(Collection $workOrders): ?Carbon
    {
        if ($workOrders->isEmpty()) {
            return null;
        }

        return $this->toCarbon(
            IssueVoucher::query()
                ->whereIn('work_order_id', $workOrders->pluck('id')->all())
                ->where('status', VoucherStatus::Posted->value)
                ->min('voucher_date')
        );
    }

    /** First delivery voucher that actually went active. */
    private function firstDeliveryDate(Project $project): ?Carbon
    {
        return $this->toCarbon(
            DeliveryVoucher::query()
                ->where('project_id', $project->getKey())
                ->whereNotNull('activated_at')
                ->min('activated_at')
        );
    }

    /**
     * A stage that only counts once EVERY work order has reached it — the
     * operation is not "manufactured" while one of its orders is still on the
     * line. Returns the date the last one landed.
     *
     * @param  Collection<int, WorkOrder>  $workOrders
     */
    private function allReached(Collection $workOrders, string $column): ?Carbon
    {
        if ($workOrders->isEmpty() || $workOrders->whereNull($column)->isNotEmpty()) {
            return null;
        }

        return $this->toCarbon($workOrders->max($column));
    }

    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof Carbon ? $value : Carbon::parse((string) $value);
    }
}
