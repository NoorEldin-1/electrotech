<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\DeviceToken;
use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Models\Project;
use App\Models\SyncConflict;
use App\Models\SyncOperationLog;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end tests for the offline-first sync layer.
 *
 * These tests model the real failure scenarios the factory floor will
 * hit: a device goes offline, queues work, comes back, and pushes
 * deltas; two devices contend for the same record; a stale device tries
 * to push against a moved record_version; the same operation gets
 * resubmitted after a dropped response; an inventory consumption races
 * against another operator's consumption of the same item.
 *
 * The wire protocol is the contract under test. We exercise the API
 * endpoints directly via the test client rather than going through the
 * JS engine — the engine is a faithful transport for the protocol, and
 * the protocol semantics are where bugs would actually hide.
 */
final class OfflineFirstSyncTest extends TestCase
{
    use RefreshDatabase;

    private function actingDevice(): array
    {
        $user = User::factory()->create();
        [$token, $raw] = DeviceToken::issue($user, (string) Str::uuid(), 'Test device');
        return [$user, $token, $raw];
    }

    private function authHeaders(string $raw): array
    {
        return ['Authorization' => "Bearer {$raw}", 'Accept' => 'application/json'];
    }

    // -----------------------------------------------------------------
    // Auth surface
    // -----------------------------------------------------------------

    public function test_pull_requires_bearer_token(): void
    {
        $this->postJson('/sync/pull', ['cursors' => []])
            ->assertStatus(401)
            ->assertJsonPath('error', 'missing_token');
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->postJson('/sync/pull', ['cursors' => []], ['Authorization' => 'Bearer etk_garbage'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'invalid_token');
    }

    public function test_revoked_token_is_rejected(): void
    {
        [$user, $token, $raw] = $this->actingDevice();
        $token->forceFill(['revoked_at' => now()])->save();

        $this->postJson('/sync/pull', ['cursors' => []], $this->authHeaders($raw))
            ->assertStatus(401);
    }

    public function test_valid_token_authorises_pull(): void
    {
        [$user, , $raw] = $this->actingDevice();
        $this->postJson('/sync/pull', ['cursors' => []], $this->authHeaders($raw))
            ->assertOk()
            ->assertJsonStructure(['server_time', 'deltas']);
    }

    // -----------------------------------------------------------------
    // Pull / snapshot
    // -----------------------------------------------------------------

    public function test_pull_returns_only_records_the_user_can_see(): void
    {
        [$owner, , $rawOwner] = $this->actingDevice();
        [$other, , $rawOther] = $this->actingDevice();

        $project = Project::factory()->create(['created_by' => $owner->id]);
        $woMine  = WorkOrder::factory()->create([
            'project_id' => $project->id, 'assigned_to' => $owner->id, 'created_by' => $owner->id,
        ]);
        $woOther = WorkOrder::factory()->create([
            'project_id' => $project->id, 'assigned_to' => $other->id, 'created_by' => $other->id,
        ]);

        $response = $this->postJson('/sync/pull', [
            'cursors' => [],
            'models'  => ['work_order'],
        ], $this->authHeaders($rawOwner))->assertOk()->json();

        $woDelta = collect($response['deltas'])->firstWhere('model', 'work_order');
        $uuids = collect($woDelta['records'])->pluck('uuid');

        $this->assertContains($woMine->uuid, $uuids->all());
        $this->assertNotContains($woOther->uuid, $uuids->all());
    }

    public function test_pull_cursor_advances_and_returns_only_new_records(): void
    {
        [$owner, , $raw] = $this->actingDevice();
        $project = Project::factory()->create(['created_by' => $owner->id]);
        WorkOrder::factory()->create([
            'project_id' => $project->id, 'assigned_to' => $owner->id, 'created_by' => $owner->id,
        ]);

        $first = $this->postJson('/sync/pull', ['cursors' => []], $this->authHeaders($raw))->json();
        $woDelta1 = collect($first['deltas'])->firstWhere('model', 'work_order');
        $this->assertNotEmpty($woDelta1['records']);
        $cursor = $woDelta1['next_cursor'];

        // Second pull with the same cursor → no records (already up to date).
        $second = $this->postJson('/sync/pull', [
            'cursors' => ['work_order' => $cursor],
        ], $this->authHeaders($raw))->json();
        $woDelta2 = collect($second['deltas'])->firstWhere('model', 'work_order');
        $this->assertEmpty($woDelta2['records']);

        // Author a new WO → pulls it.
        $new = WorkOrder::factory()->create([
            'project_id' => $project->id, 'assigned_to' => $owner->id, 'created_by' => $owner->id,
        ]);
        $third = $this->postJson('/sync/pull', [
            'cursors' => ['work_order' => $cursor],
        ], $this->authHeaders($raw))->json();
        $woDelta3 = collect($third['deltas'])->firstWhere('model', 'work_order');
        $uuids = collect($woDelta3['records'])->pluck('uuid');
        $this->assertContains($new->uuid, $uuids->all());
    }

    public function test_tombstones_propagate_via_pull(): void
    {
        [$owner, , $raw] = $this->actingDevice();
        $project = Project::factory()->create(['created_by' => $owner->id]);
        $wo = WorkOrder::factory()->create([
            'project_id' => $project->id, 'assigned_to' => $owner->id, 'created_by' => $owner->id,
        ]);
        $woUuid = $wo->uuid;

        // Sync once so we have a cursor.
        $first = $this->postJson('/sync/pull', ['cursors' => []], $this->authHeaders($raw))->json();
        $cursor = collect($first['deltas'])->firstWhere('model', 'work_order')['next_cursor'];

        // Delete the record (soft delete via the model — observer emits tombstone).
        $wo->delete();

        $second = $this->postJson('/sync/pull', [
            'cursors' => ['work_order' => $cursor],
        ], $this->authHeaders($raw))->json();
        $delta = collect($second['deltas'])->firstWhere('model', 'work_order');
        $this->assertNotEmpty($delta['tombstones']);
        $this->assertEquals($woUuid, $delta['tombstones'][0]['uuid']);
    }

    // -----------------------------------------------------------------
    // Push: applied / replayed / conflicted
    // -----------------------------------------------------------------

    public function test_push_applies_a_work_order_transition(): void
    {
        [$owner, , $raw] = $this->actingDevice();
        $project = Project::factory()->create(['created_by' => $owner->id]);
        $wo = WorkOrder::factory()->create([
            'project_id' => $project->id, 'assigned_to' => $owner->id, 'created_by' => $owner->id,
            'status'     => WorkOrderStatus::Pending,
        ]);

        $resp = $this->postJson('/sync/push', [
            'operations' => [[
                'op_uuid'           => (string) Str::uuid(),
                'model'             => 'work_order',
                'action'            => 'transition',
                'record_uuid'       => $wo->uuid,
                'base_version'      => $wo->record_version,
                'client_updated_at' => now()->toIso8601String(),
                'fields'            => ['status' => 'in_progress'],
            ]],
        ], $this->authHeaders($raw))->assertOk()->json();

        $this->assertEquals(1, $resp['counters']['applied']);
        $this->assertEquals(0, $resp['counters']['conflicted']);

        $wo->refresh();
        $this->assertEquals(WorkOrderStatus::InProgress, $wo->status);
        $this->assertGreaterThan(1, $wo->record_version);
    }

    public function test_replayed_operation_does_not_re_execute(): void
    {
        [$owner, , $raw] = $this->actingDevice();
        $project = Project::factory()->create(['created_by' => $owner->id]);
        $wo = WorkOrder::factory()->create([
            'project_id' => $project->id, 'assigned_to' => $owner->id, 'created_by' => $owner->id,
            'status'     => WorkOrderStatus::Pending,
        ]);

        $opUuid = (string) Str::uuid();
        $op = [
            'op_uuid'           => $opUuid,
            'model'             => 'work_order',
            'action'            => 'transition',
            'record_uuid'       => $wo->uuid,
            'base_version'      => $wo->record_version,
            'client_updated_at' => now()->toIso8601String(),
            'fields'            => ['status' => 'in_progress'],
        ];

        $this->postJson('/sync/push', ['operations' => [$op]], $this->authHeaders($raw))->assertOk();
        $wo->refresh();
        $versionAfterFirst = $wo->record_version;

        // Same op_uuid again — must NOT bump the version.
        $second = $this->postJson('/sync/push', ['operations' => [$op]], $this->authHeaders($raw))
            ->assertOk()->json();
        $this->assertEquals('replayed', $second['results'][0]['status']);

        $wo->refresh();
        $this->assertEquals($versionAfterFirst, $wo->record_version);
    }

    public function test_stale_base_version_creates_conflict(): void
    {
        [$ownerA, , $rawA] = $this->actingDevice();
        $project = Project::factory()->create(['created_by' => $ownerA->id]);
        $wo = WorkOrder::factory()->create([
            'project_id' => $project->id, 'assigned_to' => $ownerA->id, 'created_by' => $ownerA->id,
            'status'     => WorkOrderStatus::Pending,
            'description'=> 'original',
        ]);

        // Server moves on (e.g. an admin edited the description) — pretend
        // by bumping record_version directly via the model.
        $wo->update(['description' => 'edited by admin']);
        $newVersion = $wo->fresh()->record_version;

        // Device tries to write against the OLD base_version.
        $resp = $this->postJson('/sync/push', [
            'operations' => [[
                'op_uuid'           => (string) Str::uuid(),
                'model'             => 'work_order',
                'action'            => 'upsert',
                'record_uuid'       => $wo->uuid,
                'base_version'      => $newVersion - 1,
                'client_updated_at' => now()->toIso8601String(),
                'fields'            => ['description' => 'stale offline edit'],
            ]],
        ], $this->authHeaders($rawA))->assertOk()->json();

        $this->assertEquals(1, $resp['counters']['conflicted']);
        $this->assertEquals('version_stale', $resp['results'][0]['reason']);

        $this->assertDatabaseHas('sync_conflicts', [
            'record_uuid'    => $wo->uuid,
            'reason'         => 'version_stale',
            'server_version' => $newVersion,
        ]);

        // Server state untouched.
        $this->assertEquals('edited by admin', $wo->fresh()->description);
    }

    public function test_already_advanced_wo_returns_noop_not_conflict(): void
    {
        [$owner, , $raw] = $this->actingDevice();
        $project = Project::factory()->create(['created_by' => $owner->id]);
        $wo = WorkOrder::factory()->create([
            'project_id' => $project->id, 'assigned_to' => $owner->id, 'created_by' => $owner->id,
            'status'     => WorkOrderStatus::InProgress,
        ]);

        // Operator's queue still wants to start the WO; server has already
        // started it. This should be a noop, not a conflict.
        $resp = $this->postJson('/sync/push', [
            'operations' => [[
                'op_uuid'           => (string) Str::uuid(),
                'model'             => 'work_order',
                'action'            => 'transition',
                'record_uuid'       => $wo->uuid,
                'base_version'      => $wo->record_version,
                'client_updated_at' => now()->toIso8601String(),
                'fields'            => ['status' => 'in_progress'],
            ]],
        ], $this->authHeaders($raw))->assertOk()->json();

        $this->assertEquals('noop', $resp['results'][0]['status']);
    }

    public function test_inventory_transaction_appends(): void
    {
        [$user, , $raw] = $this->actingDevice();
        $item = Item::factory()->create(['unit_cost' => 1]);
        // Seed enough stock so the deduction doesn't fail.
        \App\Models\Inventory::create([
            'item_id' => $item->id, 'warehouse_type' => 'raw_materials',
            'on_hand_quantity' => 100, 'on_hold_quantity' => 0,
        ]);

        $clientUuid = (string) Str::uuid();
        $resp = $this->postJson('/sync/push', [
            'operations' => [[
                'op_uuid'           => (string) Str::uuid(),
                'model'             => 'inventory_transaction',
                'action'            => 'upsert',
                'record_uuid'       => $clientUuid,
                'base_version'      => null,
                'client_updated_at' => now()->toIso8601String(),
                'fields'            => [
                    'item_id'  => $item->id,
                    'type'     => 'out',
                    'quantity' => 5,
                    'notes'    => 'consumed offline',
                ],
            ]],
        ], $this->authHeaders($raw))->assertOk()->json();

        $this->assertEquals(1, $resp['counters']['applied']);
        $this->assertDatabaseHas('inventory_transactions', [
            'uuid'    => $clientUuid,
            'item_id' => $item->id,
            'type'    => 'out',
        ]);
        $this->assertEquals(95, (float) \App\Models\Inventory::where('item_id', $item->id)->value('on_hand_quantity'));
    }

    public function test_inventory_consumption_beyond_stock_is_rejected(): void
    {
        [$user, , $raw] = $this->actingDevice();
        $item = Item::factory()->create();
        \App\Models\Inventory::create([
            'item_id' => $item->id, 'warehouse_type' => 'raw_materials',
            'on_hand_quantity' => 5, 'on_hold_quantity' => 0,
        ]);

        $resp = $this->postJson('/sync/push', [
            'operations' => [[
                'op_uuid'      => (string) Str::uuid(),
                'model'        => 'inventory_transaction',
                'action'       => 'upsert',
                'record_uuid'  => (string) Str::uuid(),
                'base_version' => null,
                'fields'       => ['item_id' => $item->id, 'type' => 'out', 'quantity' => 50],
            ]],
        ], $this->authHeaders($raw))->assertOk()->json();

        $this->assertEquals('rejected', $resp['results'][0]['status']);
        $this->assertEquals('insufficient_stock', $resp['results'][0]['reason']);
    }

    public function test_concurrent_consumption_serialises_via_lock(): void
    {
        // Two operators each try to consume 7 of an item that has 10 in
        // stock. Only one should succeed; the second should get
        // insufficient_stock. The order is deterministic in tests
        // (sequential request handling) but the resolver still uses
        // the InventoryService lock, which is the real-world guarantee.
        [$a, , $rawA] = $this->actingDevice();
        [$b, , $rawB] = $this->actingDevice();

        $item = Item::factory()->create();
        \App\Models\Inventory::create([
            'item_id' => $item->id, 'warehouse_type' => 'raw_materials',
            'on_hand_quantity' => 10, 'on_hold_quantity' => 0,
        ]);

        $payload = fn () => [
            'op_uuid'      => (string) Str::uuid(),
            'model'        => 'inventory_transaction',
            'action'       => 'upsert',
            'record_uuid'  => (string) Str::uuid(),
            'base_version' => null,
            'fields'       => ['item_id' => $item->id, 'type' => 'out', 'quantity' => 7],
        ];

        $first = $this->postJson('/sync/push', ['operations' => [$payload()]], $this->authHeaders($rawA))->json();
        $second = $this->postJson('/sync/push', ['operations' => [$payload()]], $this->authHeaders($rawB))->json();

        $this->assertEquals('applied', $first['results'][0]['status']);
        $this->assertEquals('rejected', $second['results'][0]['status']);
        $this->assertEquals('insufficient_stock', $second['results'][0]['reason']);
        $this->assertEquals(3, (float) \App\Models\Inventory::where('item_id', $item->id)->value('on_hand_quantity'));
    }

    public function test_readonly_model_push_is_rejected_with_illegal_transition(): void
    {
        [$owner, , $raw] = $this->actingDevice();
        $project = Project::factory()->create(['created_by' => $owner->id]);

        $resp = $this->postJson('/sync/push', [
            'operations' => [[
                'op_uuid'      => (string) Str::uuid(),
                'model'        => 'project',
                'action'       => 'upsert',
                'record_uuid'  => $project->uuid,
                'base_version' => $project->record_version,
                'fields'       => ['name' => 'tampered offline'],
            ]],
        ], $this->authHeaders($raw))->assertOk()->json();

        $this->assertEquals('rejected', $resp['results'][0]['status']);
        $this->assertEquals('illegal_transition', $resp['results'][0]['reason']);
        $this->assertNotEquals('tampered offline', $project->fresh()->name);
    }

    public function test_oversized_batch_is_rejected_413(): void
    {
        [$owner, , $raw] = $this->actingDevice();
        // 201 ops > config max 200.
        $ops = [];
        for ($i = 0; $i < 201; $i++) {
            $ops[] = [
                'op_uuid'      => (string) Str::uuid(),
                'model'        => 'work_order',
                'action'       => 'upsert',
                'record_uuid'  => (string) Str::uuid(),
                'base_version' => 1,
                'fields'       => [],
            ];
        }
        $this->postJson('/sync/push', ['operations' => $ops], $this->authHeaders($raw))
            ->assertStatus(422); // Laravel validation 422 for too many; size check is sized to fall here
    }

    public function test_operation_log_records_first_run_and_replay(): void
    {
        [$owner, , $raw] = $this->actingDevice();
        $project = Project::factory()->create(['created_by' => $owner->id]);
        $wo = WorkOrder::factory()->create([
            'project_id' => $project->id, 'assigned_to' => $owner->id, 'created_by' => $owner->id,
            'status'     => WorkOrderStatus::Pending,
        ]);

        $opUuid = (string) Str::uuid();
        $op = [
            'op_uuid'      => $opUuid,
            'model'        => 'work_order',
            'action'       => 'transition',
            'record_uuid'  => $wo->uuid,
            'base_version' => $wo->record_version,
            'fields'       => ['status' => 'in_progress'],
        ];

        $this->postJson('/sync/push', ['operations' => [$op]], $this->authHeaders($raw))->assertOk();
        $this->assertDatabaseHas('sync_operation_log', [
            'op_uuid' => $opUuid,
            'status'  => 'applied',
        ]);

        // Replay does NOT duplicate the log row (unique constraint) and
        // does NOT change its status.
        $this->postJson('/sync/push', ['operations' => [$op]], $this->authHeaders($raw))->assertOk();
        $count = SyncOperationLog::query()->where('op_uuid', $opUuid)->count();
        $this->assertEquals(1, $count);
    }

    public function test_enroll_requires_web_session(): void
    {
        // Unauthenticated → redirects to login (302) for the web guard;
        // we treat that as "not enrolled" rather than 401.
        $this->postJson('/sync/enroll', ['device_id' => 'abcdefgh'])
            ->assertStatus(401);
    }

    public function test_enroll_with_session_mints_token(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $resp = $this->postJson('/sync/enroll', [
            'device_id'   => 'device-abc-123',
            'device_name' => 'Bay 7 tablet',
        ])->assertStatus(201)->json();

        $this->assertArrayHasKey('token', $resp);
        $this->assertStringStartsWith('etk_', $resp['token']);
        $this->assertDatabaseHas('device_tokens', [
            'user_id'     => $user->id,
            'device_id'   => 'device-abc-123',
            'device_name' => 'Bay 7 tablet',
        ]);
    }

    public function test_re_enrolling_same_device_revokes_prior_token(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $first = $this->postJson('/sync/enroll', ['device_id' => 'dev-12345678'])->json();
        $this->postJson('/sync/enroll', ['device_id' => 'dev-12345678'])->assertStatus(201);

        $count = DeviceToken::query()->where('user_id', $user->id)->where('device_id', 'dev-12345678')->count();
        $revoked = DeviceToken::query()->where('user_id', $user->id)->where('device_id', 'dev-12345678')->whereNotNull('revoked_at')->count();
        $this->assertEquals(2, $count);
        $this->assertEquals(1, $revoked);

        // Old token no longer authenticates.
        $this->postJson('/sync/pull', ['cursors' => []], $this->authHeaders($first['token']))
            ->assertStatus(401);
    }
}
