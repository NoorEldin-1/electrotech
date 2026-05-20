<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Infrastructure for the offline-first sync protocol.
 *
 *   device_tokens
 *       One row per registered client device. Used by the SyncTokenGuard to
 *       authenticate API calls without dragging in Laravel Sanctum (the
 *       project intentionally avoids extra dependencies). The token itself is
 *       stored only as a SHA-256 hash; the raw token is shown to the device
 *       exactly once at enrollment.
 *
 *   sync_tombstones
 *       Deletion markers. We cannot rely on soft-deletes to communicate "this
 *       row is gone" to clients because:
 *         (a) hard deletes leave no trace at all;
 *         (b) soft-deleted rows are filtered out of default Eloquent queries,
 *             so they'd silently disappear from pulls.
 *       Every deletion (soft OR hard) leaves a tombstone row that pull
 *       includes in its delta, so clients can purge their local copies.
 *
 *   sync_conflicts
 *       When the server rejects a client operation because the server's
 *       record_version is ahead of the client's base_version, the rejected
 *       payload is captured here for admin review. The server-side state is
 *       authoritative; this is a forensic log, not a queue to be re-applied.
 *
 *   sync_operation_log
 *       Per-operation idempotency ledger keyed on (device_id, op_uuid). The
 *       general-purpose Idempotency middleware operates at the HTTP-request
 *       level; sync operations are *batched* inside a single request, so we
 *       need finer-grained dedupe.
 *
 * Each Schema::create is guarded with hasTable() so a re-run after a partial
 * failure (e.g. MySQL strict mode rejecting a TIMESTAMP default mid-migration)
 * does not abort with "table already exists" on the already-created earlier
 * tables. The migration was authored to be idempotent under partial failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('device_tokens')) {
            Schema::create('device_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                // device_id is NOT globally unique. Re-enrolling the same
                // device produces a new row (with the prior row's revoked_at
                // stamped). Uniqueness of the *active* token per (user,
                // device) is enforced in EnrollController by revoking
                // existing actives before insert; we deliberately do not
                // try to express that as a partial unique index (MySQL
                // syntax differs from SQLite/Postgres) — it is cheap to
                // enforce in the application layer.
                $table->string('device_id', 64)->comment('Client-generated, stable across enrollments');
                $table->string('device_name')->nullable()->comment('Human label for revocation UI');
                $table->string('token_hash', 64)->unique()->comment('SHA-256 of the bearer token');
                $table->timestamp('last_used_at')->nullable();
                $table->string('last_used_ip', 45)->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'revoked_at']);
                $table->index(['user_id', 'device_id']);
            });
        }

        if (! Schema::hasTable('sync_tombstones')) {
            Schema::create('sync_tombstones', function (Blueprint $table) {
                $table->id();
                $table->string('model_type')->index()->comment('Eloquent class name, e.g. App\\Models\\WorkOrder');
                $table->char('uuid', 36)->index()->comment('uuid of the deleted record');
                $table->unsignedBigInteger('original_id')->nullable()->comment('For audit / FK chasing');
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->char('sync_origin', 36)->nullable()->comment('device_id that authored the delete');
                // useCurrent() emits `DEFAULT CURRENT_TIMESTAMP` rather
                // than no default, which MySQL strict mode rejects on a
                // NOT NULL TIMESTAMP column. The application always sets
                // both values explicitly at insert time, so the default
                // is a backstop, not load-bearing.
                $table->timestamp('deleted_at')->useCurrent()->index();
                $table->timestamp('synced_at')->useCurrent()->index()->comment('Pull cursor lives here too');

                $table->unique(['model_type', 'uuid'], 'sync_tombstones_unique');
            });
        }

        if (! Schema::hasTable('sync_conflicts')) {
            Schema::create('sync_conflicts', function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 36)->index()->comment('Conflict event id, distinct from the record uuid');
                $table->string('model_type')->index();
                $table->char('record_uuid', 36)->index()->comment('uuid of the contested record');
                $table->foreignId('device_token_id')->constrained('device_tokens')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('reason', 64)->index()->comment('version_stale, validation_failed, illegal_transition, fk_missing');
                $table->unsignedBigInteger('server_version')->nullable();
                $table->unsignedBigInteger('client_base_version')->nullable();
                $table->json('client_payload')->comment('What the client tried to write');
                $table->json('server_state')->nullable()->comment('What the server currently has, for diff display');
                $table->text('error_message')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('resolution', 32)->nullable()->comment('accepted_server, force_applied, ignored');
                $table->timestamps();

                $table->index(['resolved_at', 'created_at']);
            });
        }

        if (! Schema::hasTable('sync_operation_log')) {
            Schema::create('sync_operation_log', function (Blueprint $table) {
                $table->id();
                $table->foreignId('device_token_id')->constrained('device_tokens')->cascadeOnDelete();
                $table->char('op_uuid', 36)->comment('Client-generated id for this specific operation');
                $table->string('model_type');
                $table->char('record_uuid', 36)->nullable();
                $table->string('action', 16)->comment('upsert, delete, transition');
                $table->string('status', 16)->comment('applied, replayed, rejected, conflicted');
                $table->unsignedBigInteger('resulting_version')->nullable();
                $table->json('response_snapshot')->nullable()->comment('Cached response for replay');
                // Same strict-mode caveat as the tombstone timestamps.
                $table->timestamp('processed_at')->useCurrent();
                $table->timestamps();

                // Per-device idempotency: same op_uuid arriving twice from the
                // same device returns the cached snapshot without re-executing.
                $table->unique(['device_token_id', 'op_uuid'], 'sync_op_dedupe_unique');
                $table->index(['device_token_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_operation_log');
        Schema::dropIfExists('sync_conflicts');
        Schema::dropIfExists('sync_tombstones');
        Schema::dropIfExists('device_tokens');
    }
};
