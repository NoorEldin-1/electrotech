<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchasing modifications — slides 3 & 6: suppliers and purchase orders also
 * need attached documents (commercial registry / tax card / 1%-exemption proof
 * / scanned PO). The existing `attachments` table is Project-only; here we make
 * it polymorphic. Projects keep using `project_id` unchanged (zero risk to the
 * existing sales attachments); the new owners use the `attachable_*` morph.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('attachable_type')->nullable()->after('id');
            $table->unsignedBigInteger('attachable_id')->nullable()->after('attachable_type');
            $table->index(['attachable_type', 'attachable_id'], 'attachments_attachable_idx');
        });

        // project_id was NOT NULL; relax it so non-project attachments are valid.
        Schema::table('attachments', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropIndex('attachments_attachable_idx');
            $table->dropColumn(['attachable_type', 'attachable_id']);
        });
    }
};
