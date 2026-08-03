<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * صرف كمية زائدة عن حاجة أمر التصنيع — issuing more than the order's material
 * plan requires is a real operational event (a broken part, a re-run), but it
 * must be a *decision*, not an accident: the store keeper is stopped at the
 * posting gate, and only a user holding `issue_vouchers.approve_excess` may
 * carry on — with a written reason. These columns are that record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issue_vouchers', function (Blueprint $table) {
            $table->boolean('has_excess')->default(false)->after('total_value');
            $table->text('excess_reason')->nullable()->after('has_excess');
            $table->foreignId('excess_approved_by')->nullable()->after('excess_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('excess_approved_at')->nullable()->after('excess_approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('issue_vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('excess_approved_by');
            $table->dropColumn(['has_excess', 'excess_reason', 'excess_approved_at']);
        });
    }
};
