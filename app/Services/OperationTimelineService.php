<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttachmentCategory;
use App\Enums\InteractionType;
use App\Enums\WorkOrderPhase;
use App\Enums\WorkOrderStatus;
use App\Models\Attachment;
use App\Models\CustomerInteraction;
use App\Models\DeliveryVoucher;
use App\Models\Installation;
use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * الخط الزمني للعملية — قسم إدارة المشروعات (Presentation.pptx).
 *
 *   «عبارة عن خدمة للعملاء ومعرفة الخط الزمنى لكل عملية بحيث عند طلب اى عميل
 *    لميعاد التسليم او معرفة باقى قد ايه على التسليم نكون عارفين العملية فى انى
 *    مرحلة»
 *
 * Nineteen stages in three groups, in the deck's order (سلايد 4, 6–8):
 *
 *   preliminary  المبيعات → التواصل مع العميل → رسومات من العميل → رسم مبدئي →
 *                اعتماد الرسم المبدئي → طلب تصنيع → البدء → الانتهاء
 *   executive    جاهزية الموقع → رفع مقاسات → رسم تنفيذي → اعتماد الرسم
 *                التنفيذي → طلب التصنيع → البدء → الانتهاء
 *   handover     التسليم → بدء التركيب → انتهاء التركيب
 *
 * Deliberately DERIVED, with no stage column: each stage is reached when the
 * record that proves it exists (سلايد 5: «عند وجود فايل الرسم المبدئى داخل
 * المرفقات ده معناه ان تم الانتهاء من الرسم المبدئى»). Upload the file, log the
 * contact, start the order — the timeline moves by itself; delete the file and
 * the stage goes back to not reached.
 *
 * Every signal is loaded in grouped queries keyed by project, so forMany()
 * builds the timeline of a whole list in a fixed number of queries.
 */
class OperationTimelineService
{
    public const GROUPS = ['preliminary', 'executive', 'handover'];

    /** Attachment categories that are timeline signals, keyed by stage. */
    private const ATTACHMENT_STAGES = [
        'customer_drawings' => AttachmentCategory::CustomerDrawings,
        'preliminary_drawing' => AttachmentCategory::PreliminaryDrawing,
        'preliminary_approval' => AttachmentCategory::PreliminaryApproval,
        'site_measurement' => AttachmentCategory::SiteMeasurement,
        'executive_drawing' => AttachmentCategory::ExecutiveDrawing,
        'executive_approval' => AttachmentCategory::ExecutiveApproval,
    ];

    /**
     * The full timeline of one operation, in order. Every stage is returned
     * whether reached or not, so the screen shows what is still ahead.
     *
     * @return Collection<int, array{key: string, label: string, group: string, at: ?Carbon, reached: bool, skipped: bool, detail: ?string, days_from_start: ?int}>
     */
    public function for(Project $project): Collection
    {
        return $this->forMany(collect([$project]))[$project->getKey()];
    }

    /**
     * Timelines for many operations at once, keyed by project id.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, Collection<int, array<string, mixed>>>
     */
    public function forMany(Collection $projects): array
    {
        $signals = $this->loadSignals($projects->map(fn (Project $p) => $p->getKey())->values()->all());

        $timelines = [];
        foreach ($projects as $project) {
            $timelines[$project->getKey()] = $this->build($project, $signals);
        }

        return $timelines;
    }

    /**
     * The furthest stage the operation has actually reached — the answer to
     * «العملية فى انى مرحلة».
     *
     * @param  Collection<int, array<string, mixed>>|null  $timeline  pass a prebuilt timeline to skip the queries
     * @return array{key: string, label: string, group: ?string, group_label: ?string, at: ?Carbon}
     */
    public function currentStage(Project $project, ?Collection $timeline = null): array
    {
        $reached = ($timeline ?? $this->for($project))->filter(fn (array $s): bool => $s['reached']);

        if ($reached->isEmpty()) {
            return [
                'key' => 'not_started',
                'label' => __('resources.operation_timeline.stages.not_started'),
                'group' => null,
                'group_label' => null,
                'at' => null,
            ];
        }

        $last = $reached->last();

        return [
            'key' => $last['key'],
            'label' => $last['label'],
            'group' => $last['group'],
            'group_label' => __("resources.operation_timeline.groups.{$last['group']}"),
            'at' => $last['at'],
        ];
    }

    /**
     * Everything customer service needs in one answer: where the operation is,
     * how far along, and how long is left until the promised delivery
     * (`Project.end_date`).
     *
     * @param  Collection<int, array<string, mixed>>|null  $timeline
     * @return array{current_stage: array<string, mixed>, progress_percent: int, expected_delivery: ?Carbon, delivered_at: ?Carbon, days_remaining: ?int, days_overdue: ?int}
     */
    public function summary(Project $project, ?Collection $timeline = null): array
    {
        $timeline ??= $this->for($project);

        $done = $timeline->filter(fn (array $s): bool => $s['reached'] || $s['skipped'])->count();
        $deliveredAt = $timeline->firstWhere('key', 'delivered')['at'] ?? null;
        $expected = $this->toCarbon($project->end_date)?->startOfDay();

        $daysRemaining = null;
        $daysOverdue = null;
        if ($expected !== null && $deliveredAt === null) {
            $today = now()->startOfDay();
            if ($today->lessThanOrEqualTo($expected)) {
                $daysRemaining = (int) $today->diffInDays($expected);
            } else {
                $daysOverdue = (int) $expected->diffInDays($today);
            }
        }

        return [
            'current_stage' => $this->currentStage($project, $timeline),
            'progress_percent' => $timeline->isEmpty() ? 0 : (int) round($done * 100 / $timeline->count()),
            'expected_delivery' => $expected,
            'delivered_at' => $deliveredAt,
            'days_remaining' => $daysRemaining,
            'days_overdue' => $daysOverdue,
        ];
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return Collection<int, array<string, mixed>>
     */
    private function build(Project $project, array $signals): Collection
    {
        $id = $project->getKey();
        $attachment = fn (AttachmentCategory $c): ?Carbon => $this->toCarbon($signals['attachments'][$id][$c->value] ?? null);

        /** @var Collection<int, WorkOrder> $orders */
        $orders = $signals['work_orders'][$id] ?? collect();
        $preliminary = $orders->filter(fn (WorkOrder $wo): bool => $wo->timeline_phase === WorkOrderPhase::Preliminary);
        $executive = $orders->filter(fn (WorkOrder $wo): bool => $wo->timeline_phase === WorkOrderPhase::Executive);

        $installation = $signals['installations'][$id] ?? null;

        $stages = [
            // ── المرحلة المبدئية (سلايد 6) ──
            $this->stage('sales', 'preliminary', $this->toCarbon($project->created_at), $project->customer?->name ?? $project->client_name),
            $this->stage('customer_contact', 'preliminary', $this->toCarbon($signals['contact'][$id] ?? null)),
            $this->stage('customer_drawings', 'preliminary', $attachment(AttachmentCategory::CustomerDrawings)),
            $this->stage('preliminary_drawing', 'preliminary', $attachment(AttachmentCategory::PreliminaryDrawing)),
            $this->stage('preliminary_approval', 'preliminary', $attachment(AttachmentCategory::PreliminaryApproval)),
            ...$this->manufacturingStages('preliminary', $preliminary),

            // ── المرحلة التنفيذية (سلايد 7) ──
            $this->stage('site_readiness', 'executive', $this->toCarbon($signals['site_ready'][$id] ?? null)),
            // رفع مقاسات: the measurement file, or a recorded site survey.
            $this->stage('site_measurement', 'executive', $this->earliest(
                $attachment(AttachmentCategory::SiteMeasurement),
                $this->toCarbon($signals['site_surveys'][$id] ?? null),
            )),
            $this->stage('executive_drawing', 'executive', $attachment(AttachmentCategory::ExecutiveDrawing)),
            $this->stage('executive_approval', 'executive', $attachment(AttachmentCategory::ExecutiveApproval)),
            ...$this->manufacturingStages('executive', $executive),

            // ── التسليم والتركيب (سلايد 8) ──
            $this->stage('delivered', 'handover', $this->toCarbon($signals['delivered'][$id] ?? null)),
            $this->stage('installation_started', 'handover', $this->toCarbon($installation['started'] ?? null)),
            // «انتهاء التركيب (عن طريق محاضر تسليم الاعمال)» — completing an
            // installation requires the minutes, and uploading them completes
            // it; an upload with no installation record still counts.
            $this->stage('installation_finished', 'handover', $this->earliest(
                $this->toCarbon($installation['completed'] ?? null),
                $attachment(AttachmentCategory::HandoverMinutes),
            )),
        ];

        // The preliminary manufacturing order is optional: an operation that
        // went straight to the executive phase without one has skipped it, not
        // fallen behind on it.
        $executiveStarted = collect($stages)->contains(fn (array $s): bool => $s['group'] !== 'preliminary' && $s['at'] !== null);
        if ($preliminary->isEmpty() && $executiveStarted) {
            foreach ($stages as &$stage) {
                if (str_starts_with($stage['key'], 'preliminary_mo_')) {
                    $stage['skipped'] = true;
                }
            }
            unset($stage);
        }

        $start = collect($stages)->firstWhere('at', '!=', null)['at'] ?? null;

        return collect($stages)->map(function (array $stage) use ($start): array {
            $stage['days_from_start'] = ($start && $stage['at']) ? (int) $start->diffInDays($stage['at']) : null;

            return $stage;
        })->values();
    }

    /**
     * طلب تصنيع → البدء → الانتهاء for one phase's orders. Requested when the
     * first order is created, started when the first one starts on the floor,
     * finished only once EVERY order of the phase has finished.
     *
     * @param  Collection<int, WorkOrder>  $orders
     * @return array<int, array<string, mixed>>
     */
    private function manufacturingStages(string $phase, Collection $orders): array
    {
        $finished = ($orders->isNotEmpty() && $orders->whereNull('manufacturing_finished_at')->isEmpty())
            ? $this->toCarbon($orders->max('manufacturing_finished_at'))
            : null;

        return [
            $this->stage("{$phase}_mo_created", $phase, $this->toCarbon($orders->min('created_at')), $orders->isNotEmpty()
                ? __('resources.operation_timeline.details.work_orders', ['count' => $orders->count()])
                : null),
            $this->stage("{$phase}_mo_started", $phase, $this->toCarbon($orders->min('actual_start_date'))),
            $this->stage("{$phase}_mo_finished", $phase, $finished),
        ];
    }

    /**
     * @return array{key: string, label: string, group: string, at: ?Carbon, reached: bool, skipped: bool, detail: ?string}
     */
    private function stage(string $key, string $group, ?Carbon $at, ?string $detail = null): array
    {
        return [
            'key' => $key,
            'label' => __("resources.operation_timeline.stages.{$key}"),
            'group' => $group,
            'at' => $at,
            'reached' => $at !== null,
            'skipped' => false,
            'detail' => $at !== null ? $detail : null,
        ];
    }

    /**
     * Every signal for the given operations, one grouped query per source.
     *
     * @param  array<int, int>  $ids
     * @return array<string, mixed>
     */
    private function loadSignals(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $categories = array_map(
            fn (AttachmentCategory $c): string => $c->value,
            [...array_values(self::ATTACHMENT_STAGES), AttachmentCategory::HandoverMinutes],
        );

        $attachments = [];
        Attachment::query()
            ->whereIn('project_id', $ids)
            ->whereIn('category', $categories)
            ->groupBy('project_id', 'category')
            ->selectRaw('project_id, category, MIN(created_at) as first_at')
            ->toBase()
            ->get()
            ->each(function (object $row) use (&$attachments): void {
                $attachments[$row->project_id][$row->category] = $row->first_at;
            });

        $installations = Installation::query()
            ->whereIn('project_id', $ids)
            ->groupBy('project_id')
            ->selectRaw('project_id, MIN(started_at) as started, MIN(completed_at) as completed')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [$row->project_id => ['started' => $row->started, 'completed' => $row->completed]])
            ->all();

        return [
            'attachments' => $attachments,
            'contact' => $this->firstInteraction($ids, InteractionType::Contact),
            'site_ready' => $this->firstInteraction($ids, InteractionType::SiteReadiness, siteReady: true),
            'site_surveys' => SiteSurvey::query()->whereIn('project_id', $ids)
                ->groupBy('project_id')->pluck(DB::raw('MIN(survey_date)'), 'project_id')->all(),
            // A cancelled order never runs, so it neither starts nor blocks
            // "finished" for its phase.
            'work_orders' => WorkOrder::query()
                ->whereIn('project_id', $ids)
                ->where('status', '!=', WorkOrderStatus::Cancelled->value)
                ->get(['id', 'project_id', 'timeline_phase', 'created_at', 'actual_start_date', 'manufacturing_finished_at'])
                ->groupBy('project_id')
                ->all(),
            'delivered' => DeliveryVoucher::query()->whereIn('project_id', $ids)->whereNotNull('activated_at')
                ->groupBy('project_id')->pluck(DB::raw('MIN(activated_at)'), 'project_id')->all(),
            'installations' => $installations,
        ];
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function firstInteraction(array $ids, InteractionType $type, bool $siteReady = false): array
    {
        return CustomerInteraction::query()
            ->whereIn('project_id', $ids)
            ->where('type', $type->value)
            ->when($siteReady, fn ($q) => $q->where('site_ready', true))
            ->groupBy('project_id')
            ->pluck(DB::raw('MIN(interacted_at)'), 'project_id')
            ->all();
    }

    /** How many of the latest customer communications a timeline feed carries. */
    public const INTERACTION_FEED_LIMIT = 20;

    /**
     * The customer-communication feed of one operation (latest first) — the
     * `interactions` part of the timeline payload.
     *
     * @return array{total: int, items: Collection<int, array<string, mixed>>}
     */
    public function interactionsFor(Project $project, int $limit = self::INTERACTION_FEED_LIMIT): array
    {
        return $this->interactionsForMany(collect([$project]), $limit)[$project->getKey()];
    }

    /**
     * التواصل مع العميل (Final_Modifications_Plan 1.1) — every logged call,
     * meeting and message as a separate feed beside the stages. The 18 stage
     * keys are untouched; this is an addition to the payload, not a stage.
     *
     * A fixed number of queries for any number of operations, and the database
     * never hands back more than `$limit` rows per operation:
     *
     *   1. the totals — one grouped COUNT,
     *   2. the newest `$limit` entries per operation — ranked in SQL with
     *      ROW_NUMBER() OVER (PARTITION BY project_id ...), so an operation with
     *      thousands of logged calls costs no more than one with twenty,
     *   3. the authors of those entries (eager load).
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, array{total: int, items: Collection<int, array<string, mixed>>}>
     */
    public function interactionsForMany(Collection $projects, int $limit = self::INTERACTION_FEED_LIMIT): array
    {
        $ids = $projects->map(fn (Project $p) => $p->getKey())->values()->all();

        $totals = $ids === [] ? [] : CustomerInteraction::query()
            ->whereIn('project_id', $ids)
            ->groupBy('project_id')
            ->selectRaw('project_id, COUNT(*) as total')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [$row->project_id => (int) $row->total])
            ->all();

        $byProject = collect();

        if ($ids !== [] && $limit > 0) {
            $columns = ['id', 'project_id', 'type', 'channel', 'interacted_at', 'site_ready', 'summary', 'notes', 'next_follow_up_at', 'created_by'];

            // Rank each operation's entries newest first (id breaks ties)...
            $ranked = CustomerInteraction::query()
                ->whereIn('project_id', $ids)
                ->select($columns)
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY project_id ORDER BY interacted_at DESC, id DESC) as feed_rank');

            // ...and keep the first `$limit` of every operation. Soft-deleted
            // rows were already left out inside; the outer query reads the
            // derived table, which has no deleted_at column to scope on.
            $byProject = CustomerInteraction::query()
                ->withoutGlobalScope(SoftDeletingScope::class)
                ->fromSub($ranked, 'customer_interactions')
                ->where('feed_rank', '<=', $limit)
                ->with('createdBy:id,name')
                ->orderBy('project_id')
                ->orderBy('feed_rank')
                ->get($columns)
                ->groupBy('project_id');
        }

        $feeds = [];
        foreach ($ids as $id) {
            /** @var Collection<int, CustomerInteraction> $rows */
            $rows = $byProject->get($id, collect());

            $feeds[$id] = [
                'total' => $totals[$id] ?? 0,
                'items' => $rows->map(fn (CustomerInteraction $i): array => [
                    'id' => $i->id,
                    'at' => $i->interacted_at,
                    'type' => $i->type->value,
                    'type_label' => $i->type->getLabel(),
                    'channel' => $i->channel?->value,
                    'channel_label' => $i->channel?->getLabel(),
                    'summary' => $i->summary,
                    'notes' => $i->notes,
                    'next_follow_up_at' => $i->next_follow_up_at,
                    'site_ready' => (bool) $i->site_ready,
                    'recorded_by' => $i->createdBy?->name,
                ])->values(),
            ];
        }

        return $feeds;
    }

    private function earliest(?Carbon ...$dates): ?Carbon
    {
        $dates = array_filter($dates);

        return $dates === [] ? null : min($dates);
    }

    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof Carbon ? $value : Carbon::parse((string) $value);
    }
}
