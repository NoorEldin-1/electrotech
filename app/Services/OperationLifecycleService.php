<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Models\Project;
use DomainException;

/**
 * الإدارة العامة — drives the operation lifecycle AFTER activation (سلايد 2:
 * مرحلة التصنيع → التسليم → الإتمام). Complements SalesPipelineService (which
 * owns the sales-stage transitions up to InProgress).
 *
 *   InProgress ⇄ OnHold
 *        ↓          ↓
 *      Completed (terminal)
 *
 * Every transition asserts the source status and throws DomainException on an
 * illegal move; the calling layer surfaces the message.
 */
class OperationLifecycleService
{
    /**
     * Mark an active (or on-hold) operation as completed — supply/installation
     * finished (سلايد 2: "بعد اتمام التوريد والتركيب").
     */
    public function markCompleted(Project $project): void
    {
        $this->assertStatus($project, [ProjectStatus::InProgress, ProjectStatus::OnHold]);

        $project->status = ProjectStatus::Completed;
        $project->save();
    }

    public function putOnHold(Project $project): void
    {
        $this->assertStatus($project, [ProjectStatus::InProgress]);

        $project->status = ProjectStatus::OnHold;
        $project->save();
    }

    public function resume(Project $project): void
    {
        $this->assertStatus($project, [ProjectStatus::OnHold]);

        $project->status = ProjectStatus::InProgress;
        $project->save();
    }

    /**
     * @param  array<int, ProjectStatus>  $allowed
     */
    private function assertStatus(Project $project, array $allowed): void
    {
        if (! in_array($project->status, $allowed, true)) {
            throw new DomainException(__('errors.operations.illegal_transition', [
                'current' => $project->status?->value ?? 'unknown',
                'allowed' => implode(', ', array_map(fn (ProjectStatus $s) => $s->value, $allowed)),
            ]));
        }
    }
}
