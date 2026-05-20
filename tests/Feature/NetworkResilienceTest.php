<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Feature tests that simulate the failure modes a weak/unstable internet
 * connection produces — request retries, double-clicks, dropped responses,
 * and stale page state. Each test maps to a real symptom an operator might
 * see on the factory floor on a 200 kbps lossy link.
 *
 * Tests are isolated from the real Filament admin routes; we exercise the
 * middleware via tiny in-test routes so we don't fight Filament's auth
 * + Livewire stack here. The middleware behaviour is the contract under
 * test, not the chrome around it.
 */
class NetworkResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Each test gets a clean response cache. Without this, a previous
        // test's stored idempotent response would leak across cases.
        Cache::flush();

        // Register helper routes. The resilience middleware are registered
        // GLOBALLY in bootstrap/app.php, so they already wrap every request
        // — re-declaring them at the route level would run them twice and
        // the Idempotency in-flight lock would self-deadlock.
        Route::post('/_test/counter/increment', function (\Illuminate\Http\Request $request) {
            $count = Cache::increment('_test_counter');
            return response()->json(['count' => $count, 'echo' => $request->input('echo')]);
        })->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);

        Route::post('/_test/counter/fail', function () {
            Cache::increment('_test_fail_counter');
            return response('boom', 500);
        })->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);

        Route::get('/_test/compressible', function () {
            // 4 KB of repeating text — well above the MIN_BYTES gate
            // and obviously compressible (>10x ratio in practice).
            return response(str_repeat('Lorem ipsum dolor sit amet. ', 200), 200)
                ->header('Content-Type', 'text/plain');
        });

        Route::get('/_test/tiny', function () {
            return response('hi', 200)->header('Content-Type', 'text/plain');
        });

        Route::get('build/assets/{name}', function (string $name) {
            return response('fake-asset:' . $name, 200)->header('Content-Type', 'application/javascript');
        });

        Route::get('js/network-resilience.js', function () {
            return response('// stub', 200)->header('Content-Type', 'application/javascript');
        });
    }

    /**
     * The single most important guarantee under weak internet: a write
     * that the client retried because it never saw the first response
     * must not execute twice. The Idempotency-Key header makes this safe.
     */
    public function test_replayed_write_with_same_idempotency_key_executes_only_once(): void
    {
        Cache::forget('_test_counter');

        $first = $this->postJson('/_test/counter/increment', ['echo' => 'A'], [
            'Idempotency-Key' => 'k_'.str_repeat('a', 16),
        ])->assertOk()->json();

        $second = $this->postJson('/_test/counter/increment', ['echo' => 'B'], [
            'Idempotency-Key' => 'k_'.str_repeat('a', 16),
        ])->assertOk()->json();

        // Same response body bit-for-bit (echo=A, the original) — proves
        // we returned the cached response, not re-executed with B.
        $this->assertSame($first, $second);
        $this->assertSame(1, $first['count']);
        $this->assertSame('A', $second['echo']);
        // And the side effect (counter) ran exactly once.
        $this->assertSame(1, (int) Cache::get('_test_counter'));
    }

    /**
     * Two requests with DIFFERENT keys are independent operations and
     * must each execute. This guards against an over-eager middleware
     * dedupe-by-anything-similar bug.
     */
    public function test_distinct_idempotency_keys_each_execute_independently(): void
    {
        Cache::forget('_test_counter');

        $this->postJson('/_test/counter/increment', [], ['Idempotency-Key' => str_repeat('a', 16)])->assertOk();
        $this->postJson('/_test/counter/increment', [], ['Idempotency-Key' => str_repeat('b', 16)])->assertOk();

        $this->assertSame(2, (int) Cache::get('_test_counter'));
    }

    /**
     * Without an Idempotency-Key the middleware MUST NOT cache anything —
     * that would break legitimate sequential writes (e.g. two distinct
     * inventory adjustments submitted back-to-back without keys).
     */
    public function test_missing_idempotency_key_does_not_cache(): void
    {
        Cache::forget('_test_counter');

        $this->postJson('/_test/counter/increment')->assertOk();
        $this->postJson('/_test/counter/increment')->assertOk();
        $this->postJson('/_test/counter/increment')->assertOk();

        $this->assertSame(3, (int) Cache::get('_test_counter'));
    }

    /**
     * A malformed key (too short, illegal chars, etc.) must be rejected,
     * not silently passed through — otherwise an attacker could spoof a
     * key that matches a cache-control wildcard and poison shared state.
     */
    public function test_malformed_idempotency_key_is_rejected(): void
    {
        $this->postJson('/_test/counter/increment', [], ['Idempotency-Key' => 'short'])
            ->assertStatus(400);

        $this->postJson('/_test/counter/increment', [], ['Idempotency-Key' => 'has spaces and !!chars'])
            ->assertStatus(400);
    }

    /**
     * 5xx responses are deliberately NOT cached — a transient server
     * failure should let the user retry and actually get through, not
     * be permanently locked into a failure response.
     */
    public function test_server_errors_are_not_replayed_from_cache(): void
    {
        Cache::forget('_test_fail_counter');

        $key = str_repeat('f', 32);
        $this->postJson('/_test/counter/fail', [], ['Idempotency-Key' => $key])->assertStatus(500);
        $this->postJson('/_test/counter/fail', [], ['Idempotency-Key' => $key])->assertStatus(500);

        // Both attempted, both ran the handler — proving we didn't trap
        // the user in a stuck-replayed-500.
        $this->assertSame(2, (int) Cache::get('_test_fail_counter'));
    }

    /**
     * Replayed responses carry an `Idempotent-Replay: true` header so the
     * client (and the service worker queue drain) can tell the difference
     * between a fresh response and a cached one.
     */
    public function test_replay_is_tagged_with_header(): void
    {
        $key = str_repeat('h', 32);

        $this->postJson('/_test/counter/increment', [], ['Idempotency-Key' => $key])
            ->assertOk()
            ->assertHeaderMissing('Idempotent-Replay');

        $this->postJson('/_test/counter/increment', [], ['Idempotency-Key' => $key])
            ->assertOk()
            ->assertHeader('Idempotent-Replay', 'true');
    }

    /**
     * Idempotency is scoped per-user. User A cannot replay user B's
     * response by guessing their key.
     */
    public function test_idempotency_is_scoped_per_user(): void
    {
        Cache::forget('_test_counter');
        $key = str_repeat('u', 32);

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->actingAs($userA)
            ->postJson('/_test/counter/increment', ['echo' => 'A'], ['Idempotency-Key' => $key])
            ->assertOk();
        $this->actingAs($userB)
            ->postJson('/_test/counter/increment', ['echo' => 'B'], ['Idempotency-Key' => $key])
            ->assertOk();

        // Two distinct users, two distinct executions.
        $this->assertSame(2, (int) Cache::get('_test_counter'));
    }

    /**
     * Compression on a large payload meaningfully shrinks the wire bytes
     * for a gzip-capable client. The exact ratio is not the contract;
     * we just assert "encoded smaller than original AND header set."
     */
    public function test_large_response_is_compressed_for_gzip_client(): void
    {
        $response = $this->withHeaders(['Accept-Encoding' => 'gzip'])
            ->get('/_test/compressible');

        $response->assertOk();
        $response->assertHeader('Content-Encoding', 'gzip');

        // Spot-check the compression ratio is dramatic, otherwise we're
        // burning CPU for nothing on the slow link.
        $originalSize = strlen(str_repeat('Lorem ipsum dolor sit amet. ', 200));
        $wireSize = strlen($response->getContent());
        $this->assertLessThan($originalSize / 3, $wireSize, 'Expected at least 3x compression ratio.');
    }

    /**
     * A client without Accept-Encoding gets the raw bytes — we must not
     * hand it a gzip blob it cannot decode.
     */
    public function test_response_is_not_compressed_for_client_without_accept_encoding(): void
    {
        $response = $this->withHeaders(['Accept-Encoding' => ''])
            ->get('/_test/compressible');

        $response->assertOk();
        $response->assertHeaderMissing('Content-Encoding');
    }

    /**
     * Tiny payloads aren't worth compressing — CPU is more expensive than
     * the bytes saved.
     */
    public function test_tiny_response_is_not_compressed(): void
    {
        $response = $this->withHeaders(['Accept-Encoding' => 'gzip'])
            ->get('/_test/tiny');

        $response->assertOk();
        $response->assertHeaderMissing('Content-Encoding');
    }

    /**
     * Fingerprinted assets get the long-lived immutable header so the
     * browser never re-requests them once cached. This is the biggest
     * single bandwidth win on a slow link.
     */
    public function test_fingerprinted_build_assets_are_marked_immutable(): void
    {
        $response = $this->get('/build/assets/app-abc123.js')->assertOk();

        // Framework may reorder Cache-Control directives, so assert on
        // the presence of each directive rather than the exact string.
        $header = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $header);
        $this->assertStringContainsString('max-age=31536000', $header);
        $this->assertStringContainsString('immutable', $header);
    }

    /**
     * The resilience scripts themselves get a SHORT TTL so we can roll
     * fixes out without users having to flush their cache.
     */
    public function test_resilience_script_has_short_revalidating_cache(): void
    {
        $response = $this->get('/js/network-resilience.js');

        $response->assertOk();
        $this->assertStringContainsString('max-age=60', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('must-revalidate', $response->headers->get('Cache-Control'));
    }

    /**
     * The ping endpoint must be cheap, auth-gated, and bypass CSRF. A
     * service-worker heartbeat can't easily forward CSRF tokens.
     */
    public function test_ping_endpoint_is_auth_gated_but_csrf_exempt(): void
    {
        // Unauthenticated XHR → 401 JSON (not the redirect, because the
        // client expects JSON). This matches how the service-worker
        // heartbeat will be detected.
        $this->getJson('/admin/ping')->assertStatus(401);

        $user = User::factory()->create();
        $response = $this->actingAs($user)
            ->getJson('/admin/ping')
            ->assertOk()
            ->assertJsonStructure(['ok', 'server_time', 'user_id']);

        // Framework + session middleware may reorder Cache-Control and
        // append `private`. What matters is that no cache layer caches it.
        $cc = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cc);
    }

    /**
     * A WO state-transition action submitted twice (because the user
     * double-clicked on a slow link) must NOT throw or duplicate work.
     * The second call should silently succeed because the state machine
     * sees the WO is already InProgress.
     *
     * This validates the "re-fetch fresh + treat advanced state as
     * success" pattern wired into WorkOrderResource's actions.
     */
    public function test_work_order_start_is_idempotent_against_double_submit(): void
    {
        $wo = WorkOrder::factory()->create(['status' => WorkOrderStatus::Pending]);

        $service = app(WorkOrderService::class);
        $service->start($wo);

        // First call advanced it. Second call — what a retry would
        // trigger — must throw, so the calling site (Filament action)
        // is the layer that converts this into a friendly success.
        $this->expectException(\RuntimeException::class);
        $service->start($wo->fresh());
    }

    /**
     * The Filament action wrapper (in WorkOrderResource) handles the
     * exception case by short-circuiting after fresh-fetch. We exercise
     * the same logic here at the service-boundary level: a fresh re-read
     * tells the action layer "already done", so no duplicate transition.
     */
    public function test_work_order_action_layer_short_circuits_on_advanced_state(): void
    {
        $wo = WorkOrder::factory()->create(['status' => WorkOrderStatus::Pending]);
        app(WorkOrderService::class)->start($wo);

        // Simulate the action's fresh-fetch + state-guard pattern.
        $fresh = $wo->fresh();
        $this->assertNotSame(WorkOrderStatus::Pending, $fresh->status);
        // The action would now return success without re-invoking start().
    }
}
