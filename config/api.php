<?php

declare(strict_types=1);

/**
 * REST API configuration.
 *
 * Every knob that a production incident might need to turn is an env var, so
 * the limits can be relaxed without a code deploy. See API_Development_Plan.md
 * §3 for the contract these values implement.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Version
    |--------------------------------------------------------------------------
    |
    | Echoed back on every response as `X-API-Version` and inside `meta`.
    | Bumping this is a *breaking* change: a new `routes/api/vN.php` file is
    | added alongside the old one, never edited in place.
    |
    */

    'version' => '1',

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | `max_per_page` is a hard ceiling, not a clamp. A client asking for more
    | gets a 422 telling it the cap — silently returning fewer rows than asked
    | is how clients ship pagination bugs that nobody notices for months.
    |
    */

    'pagination' => [
        'default_per_page' => (int) env('API_DEFAULT_PER_PAGE', 25),
        'max_per_page' => (int) env('API_MAX_PER_PAGE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits (requests per minute)
    |--------------------------------------------------------------------------
    |
    | Keyed on the authenticated user id, falling back to IP for guests. The
    | `auth` limiter is keyed on email+IP instead, so one attacker cannot lock
    | out a legitimate user by burning their quota from elsewhere.
    |
    */

    'rate_limits' => [
        'auth' => (int) env('API_RATE_LIMIT_AUTH', 5),
        'read' => (int) env('API_RATE_LIMIT_READ', 120),
        'write' => (int) env('API_RATE_LIMIT_WRITE', 40),
        'reports' => (int) env('API_RATE_LIMIT_REPORTS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tokens
    |--------------------------------------------------------------------------
    |
    | `ttl_minutes` default is 30 days: long enough that a warehouse tablet is
    | not re-authenticating mid-shift, short enough that a lost device stops
    | working within a month even if nobody remembers to revoke it.
    |
    | `max_per_user` caps how many live tokens one account may hold. Hitting
    | the cap evicts the least-recently-used token rather than refusing the
    | login — a user locked out of their own account by an old tablet they no
    | longer own would be a worse failure than silently dropping that tablet.
    |
    */

    'tokens' => [
        'ttl_minutes' => (int) env('API_TOKEN_TTL_MINUTES', 60 * 24 * 30),
        'max_per_user' => (int) env('API_MAX_TOKENS_PER_USER', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Token abilities (scopes)
    |--------------------------------------------------------------------------
    |
    | A token's abilities NARROW the user; they never widen. The effective
    | permission set is `user_permissions ∩ token_abilities`, and both gates
    | must pass on every request.
    |
    | The names mirror the module list in API_Development_Plan.md §5, so a
    | warehouse tablet can be issued a token limited to ['inventory'] and stays
    | harmless even if the device is lost while the account is a manager's.
    |
    | '*' means "everything this user may do" and is the default on login.
    |
    */

    'abilities' => [
        'identity',
        'master-data',
        'sales',
        'technical-office',
        'procurement',
        'inventory',
        'manufacturing',
        'delivery',
        'finance',
        'reports',
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | When true, a write without an `Idempotency-Key` header is rejected with
    | 400 instead of being executed unprotected. Defaults ON everywhere: a
    | client that forgets the header should find out on day one of integration,
    | not after it double-posts an issue voucher in production.
    |
    | The replay machinery itself lives in App\Http\Middleware\Idempotency and
    | is toggled separately by NETWORK_RESILIENCE_IDEMPOTENCY.
    |
    */

    'require_idempotency_key' => (bool) env('API_REQUIRE_IDEMPOTENCY_KEY', true),

    /*
    |--------------------------------------------------------------------------
    | Documentation
    |--------------------------------------------------------------------------
    |
    | The generated Scribe docs are public (they describe the contract, not the
    | data). `/docs` is already taken by the Arabic end-user manual in
    | routes/web.php, so the API docs live at `/api/docs`.
    |
    | The og_* values drive the link-preview card when the docs URL is pasted
    | into WhatsApp / Twitter / Slack.
    |
    */

    'docs' => [
        'enabled' => (bool) env('API_DOCS_ENABLED', true),
        'path' => 'api/docs',

        /*
         * The PUBLIC origin the documentation describes.
         *
         * Deliberately NOT config('app.url'). The docs are a static artifact:
         * they are generated on a developer's machine (where APP_URL is
         * http://localhost:8001) and committed, then served unchanged from
         * production. Deriving this from APP_URL would bake "localhost" into
         * the Base URL, the OpenAPI `servers` entry and the link-preview card
         * that the mobile developer and anyone opening the shared link sees.
         *
         * So it is pinned to the production origin regardless of who builds
         * the docs, and stays env-overridable for a staging deployment.
         */
        'public_url' => rtrim((string) env('API_DOCS_PUBLIC_URL', 'https://app.electrotech.findosystem.com'), '/'),
        'og_title' => env('API_DOCS_OG_TITLE', 'Electrotech API — توثيق واجهات النظام'),
        'og_description' => env(
            'API_DOCS_OG_DESCRIPTION',
            'التوثيق الرسمي لواجهات برمجة تطبيقات منصة إلكتروتك: المصادقة، المبيعات، المشتريات، المخازن، التصنيع والحسابات.',
        ),
        'og_image' => env('API_DOCS_OG_IMAGE', '/electrotech-logo.jpg'),
        'twitter_site' => env('API_DOCS_TWITTER_SITE'),
    ],

];
