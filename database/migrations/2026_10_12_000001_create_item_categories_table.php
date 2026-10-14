<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item categories (فئات الأصناف) — the factory-warehouse catalog groups its
 * ~400 items into sections (شرائط نحاس، قطاعات ألومنيوم، جوانات، …). Kept as
 * a flat, user-manageable table so new groups need no code change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_categories');
    }
};
