<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales pipeline expansion for the projects table.
 *
 * Adds:
 *  - Technical specification fields captured at operation intake
 *    (Slide 2 of Sales_Department.md).
 *  - Sales-stage bookkeeping: alarm reminders, SMB tracking,
 *    consultant acceptance email, manager approval gate, and
 *    Lost-list capture (reason / note / winning competitor).
 *  - Composite (status, created_at desc) index for the per-stage
 *    Tender / In-Hand / Active / Lost list pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('engineer_name')->nullable()->after('consultant_name');
            $table->string('electric_current')->nullable()->after('engineer_name');
            $table->string('model')->nullable()->after('electric_current');
            $table->string('section_type')->nullable()->after('model');
            $table->unsignedSmallInteger('poles_count')->nullable()->after('section_type');
            $table->unsignedInteger('quantity')->nullable()->after('poles_count');
            $table->string('project_location')->nullable()->after('quantity');
            $table->string('arrival_method')->nullable()->after('project_location');

            $table->timestamp('alarm_at')->nullable()->after('arrival_method');
            $table->string('alarm_note')->nullable()->after('alarm_at');

            $table->string('smb_status')->nullable()->after('alarm_note');
            $table->date('smb_received_at')->nullable()->after('smb_status');
            $table->date('acceptance_email_at')->nullable()->after('smb_received_at');

            $table->timestamp('manager_approved_at')->nullable()->after('acceptance_email_at');
            $table->foreignId('manager_approved_by')
                ->nullable()
                ->after('manager_approved_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('lost_reason')->nullable()->after('manager_approved_by');
            $table->text('lost_reason_note')->nullable()->after('lost_reason');
            $table->string('winning_competitor')->nullable()->after('lost_reason_note');

            $table->index('arrival_method', 'projects_arrival_method_idx');
            $table->index('alarm_at', 'projects_alarm_at_idx');
            $table->index('lost_reason', 'projects_lost_reason_idx');
            $table->index(['status', 'created_at'], 'projects_status_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('projects_status_created_at_idx');
            $table->dropIndex('projects_lost_reason_idx');
            $table->dropIndex('projects_alarm_at_idx');
            $table->dropIndex('projects_arrival_method_idx');

            $table->dropConstrainedForeignId('manager_approved_by');

            $table->dropColumn([
                'engineer_name',
                'electric_current',
                'model',
                'section_type',
                'poles_count',
                'quantity',
                'project_location',
                'arrival_method',
                'alarm_at',
                'alarm_note',
                'smb_status',
                'smb_received_at',
                'acceptance_email_at',
                'manager_approved_at',
                'lost_reason',
                'lost_reason_note',
                'winning_competitor',
            ]);
        });
    }
};
