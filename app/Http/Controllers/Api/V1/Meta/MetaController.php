<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Meta;

use App\Http\Api\EnumPresenter;
use App\Http\Controllers\Api\V1\ApiController;
use BackedEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * @group 0. Meta
 *
 * Service discovery and shared vocabulary. Call these once at app start; the
 * payloads change only when the platform is redeployed.
 */
class MetaController extends ApiController
{
    /**
     * Service info
     *
     * A cheap, unauthenticated liveness + version probe. Use it as the app's
     * connectivity check and to detect that a client build is older than the
     * deployed API.
     *
     * @unauthenticated
     *
     * @response 200 scenario="Success" {"data":{"service":"Electrotech","api_version":"1","server_time":"2026-08-05T09:30:00+00:00","locales":["en","ar"],"default_locale":"en","token_ttl_minutes":43200,"max_per_page":100,"requires_idempotency_key":true,"abilities":["identity","sales"],"docs_url":"https://app.electrotech.findosystem.com/api/docs"},"meta":{"request_id":"9f1c...","api_version":"1"}}
     */
    public function index(): JsonResponse
    {
        return $this->respond([
            'service' => config('app.name'),
            'api_version' => (string) config('api.version'),
            'server_time' => now()->toIso8601String(),
            'locales' => ['en', 'ar'],
            'default_locale' => (string) config('app.locale'),

            // Published so the client can schedule its own token rotation
            // instead of hard-coding a TTL that drifts from the server's.
            'token_ttl_minutes' => (int) config('api.tokens.ttl_minutes'),
            'max_per_page' => (int) config('api.pagination.max_per_page'),
            'requires_idempotency_key' => (bool) config('api.require_idempotency_key'),
            'abilities' => array_values((array) config('api.abilities', [])),
            'docs_url' => url((string) config('api.docs.path')),
        ]);
    }

    /**
     * Enum catalog
     *
     * Every enumerated value in the platform — project statuses, item types,
     * voucher statuses, units of measure — as `{value, label, color}`.
     *
     * Build dropdowns and status badges from this instead of hard-coding
     * options in Dart. A new case added on the server then appears in the app
     * without a release, and the Arabic labels stay in one place. `label`
     * honours `Accept-Language`; `color` is the same semantic colour the web
     * panel paints that state.
     *
     * @authenticated
     *
     * @queryParam only string Comma-separated enum keys to return instead of all. Example: project_status,item_type
     *
     * @response 200 scenario="Success" {"data":{"project_status":[{"value":"tender","label":"Tender","color":"info"},{"value":"in_progress","label":"In Progress","color":"primary"}],"item_type":[{"value":"raw_material","label":"Raw Material","color":"info"}]},"meta":{"request_id":"9f1c...","api_version":"1"}}
     */
    public function enums(Request $request): JsonResponse
    {
        $catalog = $this->enumCatalog();

        $only = trim((string) $request->query('only', ''));

        if ($only !== '') {
            $requested = array_filter(array_map('trim', explode(',', $only)));
            $unknown = array_diff($requested, array_keys($catalog));

            if ($unknown !== []) {
                $this->failValidation('only', sprintf(
                    'Unknown enum key(s): %s. Allowed: %s.',
                    implode(', ', $unknown),
                    implode(', ', array_keys($catalog)),
                ));
            }

            $catalog = array_intersect_key($catalog, array_flip($requested));
        }

        return $this->respond(
            array_map(
                static fn (string $class): array => EnumPresenter::cases($class),
                $catalog,
            ),
        );
    }

    /**
     * Discovers every backed enum in app/Enums and keys it by snake_case name
     * (`ProjectStatus` → `project_status`).
     *
     * Discovery rather than a hand-maintained list: an enum added for a future
     * module then reaches the mobile app automatically, and there is no second
     * place to forget to update. The scan is filesystem-only and the result is
     * memoized per request — and in production the response is small enough
     * that the client fetches it once per session.
     *
     * @return array<string, class-string<BackedEnum>>
     */
    private function enumCatalog(): array
    {
        static $catalog = null;

        if ($catalog !== null) {
            return $catalog;
        }

        $catalog = [];

        foreach (Finder::create()->files()->in(app_path('Enums'))->name('*.php') as $file) {
            $class = 'App\\Enums\\'.$file->getBasename('.php');

            if (! enum_exists($class) || ! is_subclass_of($class, BackedEnum::class)) {
                continue;
            }

            $catalog[Str::snake($file->getBasename('.php'))] = $class;
        }

        ksort($catalog);

        return $catalog;
    }
}
