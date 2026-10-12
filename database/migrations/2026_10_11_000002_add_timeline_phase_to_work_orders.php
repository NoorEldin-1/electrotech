<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Presentation.pptx سلايد 4 — each work order belongs to one of the two
 * manufacturing orders on the operation timeline: `preliminary` (after the
 * preliminary drawing is approved) or `executive` (after the shop drawing is
 * approved).
 *
 * Backfill: an order created before its operation's first shop-drawing
 * approval was uploaded is `preliminary`; everything else — including every
 * order on an operation with no approval on file, which is how all existing
 * orders were run — is `executive`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('timeline_phase', 20)->default('executive')->after('status');
            $table->index(['project_id', 'timeline_phase']);
        });

        $approvals = DB::table('attachments')
            ->where('category', 'executive_approval')
            ->whereNull('deleted_at')
            ->whereNotNull('project_id')
            ->groupBy('project_id')
            ->pluck(DB::raw('MIN(created_at)'), 'project_id');

        foreach ($approvals as $projectId => $approvedAt) {
            DB::table('work_orders')
                ->where('project_id', $projectId)
                ->where('created_at', '<', $approvedAt)
                ->update(['timeline_phase' => 'preliminary']);
        }
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'timeline_phase']);
            $table->dropColumn('timeline_phase');
        });
    }
};
