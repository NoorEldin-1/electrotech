<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * متابعة العملاء — قسم إدارة العملاء (Presentation.pptx سلايد 6–7). Logs every
 * touchpoint with the customer on an operation. Two of them drive the
 * operation timeline: the first `contact` ("التواصل مع العميل") and the first
 * `site_readiness` marked ready ("جاهزية الموقع").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('type', 30);
            $table->string('channel', 30)->nullable();
            $table->dateTime('interacted_at');
            $table->boolean('site_ready')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_interactions');
    }
};
