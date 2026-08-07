<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Foundation;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * The wire contract from API_Development_Plan.md §3, asserted directly.
 *
 * These tests are module-agnostic on purpose: they use Module 1 endpoints as a
 * vehicle, but what they protect is the envelope, the headers, the error
 * codes and the idempotency behaviour that EVERY later module inherits. If one
 * of these breaks, the Flutter client breaks everywhere at once.
 */
class ApiContractTest extends ApiTestCase
{
    public function test_the_meta_endpoint_is_public_and_describes_the_service(): void
    {
        $response = $this->apiGet(self::BASE.'/meta');

        $response->assertOk();
        $this->assertItemEnvelope($response);
        $response->assertJsonPath('data.api_version', '1');
        $response->assertJsonStructure([
            'data' => [
                'service', 'api_version', 'server_time', 'locales', 'default_locale',
                'token_ttl_minutes', 'max_per_page', 'requires_idempotency_key',
                'abilities', 'docs_url',
            ],
        ]);
    }

    public function test_every_response_carries_the_version_and_request_id_headers(): void
    {
        $response = $this->apiGet(self::BASE.'/meta');

        $response->assertHeader('X-API-Version', '1');
        $response->assertHeader('X-Request-Id');

        // The header and the envelope must agree, or a support ticket quoting
        // one cannot be matched to a log line carrying the other.
        $this->assertSame(
            $response->headers->get('X-Request-Id'),
            $response->json('meta.request_id'),
        );
    }

    public function test_a_client_supplied_request_id_is_echoed_back(): void
    {
        $response = $this->apiGet(self::BASE.'/meta', ['X-Request-Id' => 'flutter-abc-123']);

        $response->assertHeader('X-Request-Id', 'flutter-abc-123');
    }

    public function test_a_malformed_client_request_id_is_replaced_not_trusted(): void
    {
        // Request ids reach the log file; an unbounded attacker-controlled
        // string must not.
        $response = $this->apiGet(self::BASE.'/meta', [
            'X-Request-Id' => "bad\nvalue with spaces ".str_repeat('x', 500),
        ]);

        $this->assertNotSame(
            "bad\nvalue with spaces ".str_repeat('x', 500),
            $response->headers->get('X-Request-Id'),
        );
    }

    public function test_security_headers_are_present(): void
    {
        $response = $this->apiGet(self::BASE.'/meta');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        // `private` blocks shared caches; `no-cache` still allows the client's
        // own copy so conditional GET (ETag) can work. Symfony normalizes the
        // directive order, so assert on the parts rather than the string.
        $cacheControl = explode(', ', (string) $response->headers->get('Cache-Control'));
        $this->assertContains('private', $cacheControl);
        $this->assertContains('no-cache', $cacheControl);
        $this->assertNotContains('no-store', $cacheControl);
    }

    public function test_an_unknown_route_returns_the_error_envelope_not_an_html_page(): void
    {
        $response = $this->apiGet(self::BASE.'/there-is-no-such-thing');

        $response->assertStatus(404);
        $this->assertErrorEnvelope($response, 'not_found');
    }

    public function test_a_wrong_verb_returns_405_with_the_allowed_methods(): void
    {
        $response = $this->apiJson('DELETE', self::BASE.'/meta');

        $response->assertStatus(405);
        $this->assertErrorEnvelope($response, 'method_not_allowed');
        $response->assertJsonStructure(['error' => ['details' => ['allowed_methods']]]);
    }

    public function test_a_non_json_body_is_rejected(): void
    {
        $user = $this->userWith(['users.view', 'users.create']);

        $response = $this->actingAsApi($user)->call(
            'POST',
            self::BASE.'/users',
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid(),
            ],
            'name=Ahmed',
        );

        // Two parsing paths for one endpoint means two sets of edge cases.
        $this->assertSame(415, $response->status());
    }

    public function test_a_write_without_an_idempotency_key_is_rejected(): void
    {
        $user = $this->userWith(['users.view', 'users.create']);

        // Deliberately omits the header the harness adds by default.
        $response = $this->actingAsApi($user)->json('POST', self::BASE.'/users', [
            'name' => 'Ahmed',
            'email' => 'ahmed@electrotech.com',
            'password' => 'secret123',
            'roles' => ['Sales'],
        ], ['Accept' => 'application/json']);

        $response->assertStatus(400);
        $this->assertErrorEnvelope($response, 'bad_request');
        $this->assertDatabaseMissing('users', ['email' => 'ahmed@electrotech.com']);
    }

    public function test_reads_do_not_require_an_idempotency_key(): void
    {
        $user = $this->userWith(['users.view']);

        $this->actingAsApi($user)
            ->json('GET', self::BASE.'/users', [], ['Accept' => 'application/json'])
            ->assertOk();
    }

    public function test_replaying_a_write_with_the_same_key_does_not_write_twice(): void
    {
        $user = $this->userWith(['users.view', 'users.create']);
        $key = (string) Str::uuid();

        $payload = [
            'name' => 'Ahmed Hassan',
            'email' => 'ahmed@electrotech.com',
            'password' => 'secret123',
            'roles' => ['Sales'],
        ];

        // Authenticate ONCE and reuse the token: a real client retrying a
        // dropped request sends the same credential, and the idempotency scope
        // is keyed to it.
        $this->actingAsApi($user);

        $first = $this->apiPost(self::BASE.'/users', $payload, ['Idempotency-Key' => $key]);
        $first->assertCreated();

        // The retry a mobile client makes when the response never arrived.
        $replay = $this->apiPost(self::BASE.'/users', $payload, ['Idempotency-Key' => $key]);

        $replay->assertCreated();
        $replay->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));

        // This is the whole point: one user, not two.
        $this->assertSame(1, User::where('email', 'ahmed@electrotech.com')->count());
    }

    public function test_one_caller_cannot_replay_another_callers_cached_response(): void
    {
        $alice = $this->userWith(['users.view', 'users.create']);
        $bob = $this->userWith(['users.view', 'users.create']);
        $sharedKey = (string) Str::uuid();

        $this->actingAsApi($alice)->apiPost(self::BASE.'/users', [
            'name' => 'Alice Hire', 'email' => 'alice-hire@electrotech.com',
            'password' => 'secret123', 'roles' => ['Sales'],
        ], ['Idempotency-Key' => $sharedKey])->assertCreated();

        // Bob reuses the same key. He must NOT receive Alice's cached body —
        // that would leak a record he never created and may not be allowed to
        // see. He gets a real execution instead.
        $bobResponse = $this->actingAsApi($bob)->apiPost(self::BASE.'/users', [
            'name' => 'Bob Hire', 'email' => 'bob-hire@electrotech.com',
            'password' => 'secret123', 'roles' => ['Sales'],
        ], ['Idempotency-Key' => $sharedKey]);

        $bobResponse->assertCreated();
        $bobResponse->assertJsonPath('data.email', 'bob-hire@electrotech.com');
        $this->assertNull($bobResponse->headers->get('Idempotent-Replay'));
    }

    public function test_a_different_idempotency_key_does_write_again(): void
    {
        $user = $this->userWith(['users.view', 'users.create']);

        $this->actingAsApi($user)->apiPost(self::BASE.'/users', [
            'name' => 'A', 'email' => 'a@electrotech.com', 'password' => 'secret123', 'roles' => ['Sales'],
        ])->assertCreated();

        $this->actingAsApi($user)->apiPost(self::BASE.'/users', [
            'name' => 'B', 'email' => 'b@electrotech.com', 'password' => 'secret123', 'roles' => ['Sales'],
        ])->assertCreated();

        $this->assertSame(2, User::whereIn('email', ['a@electrotech.com', 'b@electrotech.com'])->count());
    }

    public function test_detail_reads_emit_an_etag_and_honour_if_none_match(): void
    {
        $user = $this->userWith(['users.view']);

        $first = $this->actingAsApi($user)->apiGet(self::BASE.'/auth/me');
        $first->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotNull($etag, 'Detail reads must emit an ETag; mobile bandwidth depends on it.');

        $second = $this->actingAsApi($user)
            ->apiGet(self::BASE.'/auth/me', ['If-None-Match' => $etag]);

        $second->assertStatus(304);
        $this->assertSame('', $second->content());
    }

    public function test_a_changed_resource_produces_a_new_etag(): void
    {
        $user = $this->userWith(['users.view']);

        $etag = $this->actingAsApi($user)->apiGet(self::BASE.'/auth/me')->headers->get('ETag');

        $this->actingAsApi($user)->apiPatch(self::BASE.'/auth/profile', ['name' => 'Renamed'])
            ->assertOk();

        $after = $this->actingAsApi($user)
            ->apiGet(self::BASE.'/auth/me', ['If-None-Match' => $etag]);

        // A stale ETag must NOT produce a 304, or the app would show the old
        // name until the token expired.
        $after->assertOk();
        $after->assertJsonPath('data.name', 'Renamed');
    }

    public function test_the_login_endpoint_is_rate_limited(): void
    {
        config(['api.rate_limits.auth' => 3]);
        $this->userWith(['users.view'], ['email' => 'a@electrotech.com']);

        for ($i = 0; $i < 3; $i++) {
            $this->apiPost(self::BASE.'/auth/login', [
                'email' => 'a@electrotech.com',
                'password' => 'wrong',
                'device_name' => 'Phone',
            ])->assertStatus(422);
        }

        $blocked = $this->apiPost(self::BASE.'/auth/login', [
            'email' => 'a@electrotech.com',
            'password' => 'wrong',
            'device_name' => 'Phone',
        ]);

        $blocked->assertStatus(429);
        $this->assertErrorEnvelope($blocked, 'rate_limited');
        $blocked->assertHeader('Retry-After');
    }

    public function test_read_endpoints_are_rate_limited_per_user(): void
    {
        config(['api.rate_limits.read' => 3]);
        $user = $this->userWith(['users.view']);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAsApi($user)->apiGet(self::BASE.'/users')->assertOk();
        }

        $this->actingAsApi($user)->apiGet(self::BASE.'/users')->assertStatus(429);

        // A different user is unaffected: one client on a bad network must not
        // exhaust the whole factory's quota.
        $other = $this->userWith(['users.view']);
        $this->actingAsApi($other)->apiGet(self::BASE.'/users')->assertOk();
    }

    public function test_error_messages_follow_the_accept_language_header(): void
    {
        $arabic = $this->apiGet(self::BASE.'/auth/me', ['Accept-Language' => 'ar']);
        $english = $this->apiGet(self::BASE.'/auth/me', ['Accept-Language' => 'en']);

        $arabic->assertStatus(401);
        $english->assertStatus(401);

        // The code is stable across languages; only the prose changes.
        $this->assertSame('unauthenticated', $arabic->json('error.code'));
        $this->assertSame('unauthenticated', $english->json('error.code'));
        $this->assertNotSame($arabic->json('error.message'), $english->json('error.message'));
    }

    public function test_a_weighted_accept_language_list_is_negotiated(): void
    {
        $response = $this->apiGet(self::BASE.'/meta', [
            'Accept-Language' => 'ar-EG,ar;q=0.9,en;q=0.8',
        ]);

        $response->assertHeader('Content-Language', 'ar');
    }

    public function test_the_enum_catalog_serves_value_label_and_colour(): void
    {
        $user = $this->userWith(['users.view']);

        $response = $this->actingAsApi($user)->apiGet(self::BASE.'/meta/enums');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['project_status' => [['value', 'label', 'color']]]]);

        $values = array_column($response->json('data.project_status'), 'value');
        $this->assertContains('tender', $values);
        $this->assertContains('in_progress', $values);

        // Labels must be resolved, not raw keys — the client renders them.
        foreach ($response->json('data.project_status') as $case) {
            $this->assertStringNotContainsString('resources.enums.', $case['label']);
        }
    }

    public function test_the_enum_catalog_can_be_narrowed_and_rejects_unknown_keys(): void
    {
        $user = $this->userWith(['users.view']);

        $this->actingAsApi($user)->apiGet(self::BASE.'/meta/enums?only=item_type')
            ->assertOk()
            ->assertJsonStructure(['data' => ['item_type']])
            ->assertJsonMissingPath('data.project_status');

        $this->actingAsApi($user)->apiGet(self::BASE.'/meta/enums?only=nonsense')
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['only']]]);
    }

    public function test_enum_labels_follow_the_accept_language_header(): void
    {
        $user = $this->userWith(['users.view']);

        $en = $this->actingAsApi($user)
            ->apiGet(self::BASE.'/meta/enums?only=project_status', ['Accept-Language' => 'en']);
        $ar = $this->actingAsApi($user)
            ->apiGet(self::BASE.'/meta/enums?only=project_status', ['Accept-Language' => 'ar']);

        $this->assertNotSame(
            $en->json('data.project_status.0.label'),
            $ar->json('data.project_status.0.label'),
        );
    }

    public function test_stack_traces_never_leak_when_debug_is_off(): void
    {
        config(['app.debug' => false]);

        // Force a genuine server error through an endpoint that exists.
        \Illuminate\Support\Facades\Route::get('/api/v1/__boom', function (): void {
            throw new \RuntimeException('internal detail that must not leak');
        });

        $response = $this->apiGet(self::BASE.'/__boom');

        $response->assertStatus(500);
        $this->assertErrorEnvelope($response, 'server_error');
        $this->assertStringNotContainsString('internal detail', $response->content());
        $response->assertJsonMissingPath('error.details');
    }
}
