<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المنتجات التامة لأمر التصنيع — a manufacturing order produces more than one
 * finished product, each with its own planned quantity. The single
 * `work_orders.output_item_id` column could only ever carry one, and the
 * order's `planned_quantity` had no per-product breakdown, which meant the
 * material table (and therefore the issue vouchers) could not be scaled
 * correctly for a mixed order.
 *
 * `work_orders.output_item_id` is deliberately KEPT and now mirrors the first
 * output row: the quality sheet, the manufacturing-finished notification, the
 * material-variance PDF and the production reports all read it, and a
 * denormalized "primary product" keeps every one of them working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();

            // The plan for THIS product. The order's planned_quantity is the
            // sum of these (kept denormalized: it is the denominator of the
            // efficiency / waste / cost-variance figures).
            $table->decimal('planned_quantity', 14, 4)->default(0);

            // Filled at the QA-submission stage (مراحل أمر التشغيل), never on
            // the create/edit form.
            $table->decimal('produced_quantity', 14, 4)->default(0);
            $table->decimal('waste_quantity', 14, 4)->default(0);

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['work_order_id', 'item_id']);
        });

        // Backfill: every existing order with a finished product becomes a
        // one-row multi-output order carrying its current quantities.
        DB::table('work_orders')
            ->whereNotNull('output_item_id')
            ->orderBy('id')
            ->chunkById(200, function ($orders) {
                $now = now();

                DB::table('work_order_outputs')->insert(
                    collect($orders)->map(fn ($order) => [
                        'work_order_id' => $order->id,
                        'item_id' => $order->output_item_id,
                        'planned_quantity' => $order->planned_quantity ?? 0,
                        'produced_quantity' => $order->produced_quantity ?? 0,
                        'waste_quantity' => $order->waste_quantity ?? 0,
                        'notes' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_outputs');
    }
};
