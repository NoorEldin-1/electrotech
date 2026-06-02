<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * معاينة الموقع — Site surveys (سلايد 1: "دراسة المشروع والذهاب ورفع مقاسات
 * الموقع وعمل رسومات للعملية"). Records the engineering site visit: date,
 * measurements and surveyor. Drawings/measurement files attach via the
 * project's attachments (categories drowing / site_measurement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->date('survey_date');
            $table->text('measurements')->nullable();
            $table->foreignId('surveyed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_surveys');
    }
};
