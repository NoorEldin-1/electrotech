<?php

use Knuckles\Scribe\Config\AuthIn;
use Knuckles\Scribe\Config\Defaults;
use Knuckles\Scribe\Extracting\Strategies;

use function Knuckles\Scribe\Config\configureStrategy;
use function Knuckles\Scribe\Config\removeStrategies;

// Only the most common configs are shown. See the https://scribe.knuckles.wtf/laravel/reference/config for all.

/*
|--------------------------------------------------------------------------
| Production guard — DO NOT REMOVE
|--------------------------------------------------------------------------
|
| Scribe is a `require-dev` package, and production deploys with
| `composer install --no-dev`. Laravel's LoadConfiguration bootstrapper
| require()s EVERY file in config/ on EVERY request — so the moment this file
| evaluates `AuthIn::BEARER` or `Defaults::METADATA_STRATEGIES` on a server
| where the package is absent, the result is:
|
|     Error: Class "Knuckles\Scribe\Config\AuthIn" not found
|
| That is not a broken artisan command. It is a fatal on every single request:
| the admin panel, the API, the whole site. `php artisan config:cache` during
| deploy dies first, so the deploy fails and the app is left in maintenance
| mode.
|
| The `use` statements above are safe (PHP does not autoload on import), so
| bailing out here — before any class is touched — keeps the file inert in
| production while remaining fully functional locally, where the package is
| installed and `scribe:generate` runs.
|
| Returning an empty array is fine: Scribe's service provider is not
| registered in production either (package auto-discovery regenerates the
| manifest without it), so nothing ever reads this config there.
|
| Verified with a --no-dev simulation before the first production deploy.
*/
if (! class_exists(AuthIn::class)) {
    return [];
}

return [
    // The HTML <title> for the generated documentation.
    'title' => config('app.name').' API — توثيق واجهات النظام',

    // A short description of your API. Will be included in the docs webpage, Postman collection and OpenAPI spec.
    'description' => 'REST API for the Electrotech ERP platform: sales pipeline, technical office, procurement, warehouse, manufacturing, delivery and accounting.',

    // Text to place in the "Introduction" section, right after the `description`. Markdown and HTML are supported.
    'intro_text' => <<<'INTRO'
            Welcome to the Electrotech ERP API. This is the contract a client
            application — currently the Flutter mobile app — uses to drive the
            same business processes the web admin panel drives.

            **Base URL:** every endpoint below is relative to `/api/v1`.

            ## The five things to get right first

            1. **Always send `Accept: application/json`.** Without it you may
               receive an HTML error page your client cannot parse.
            2. **Send the bearer token** returned by `POST /auth/login` on every
               other request: `Authorization: Bearer 12|xxxxx`.
            3. **Send an `Idempotency-Key` header on every write**
               (POST/PUT/PATCH/DELETE). Generate one UUID per *user action* and
               reuse it across your own retries. A replayed key returns the
               original response without executing again — this is what stops a
               dropped response on a weak connection from creating two issue
               vouchers. A write without the header is rejected with 400.
            4. **Branch on `error.code`, never on `error.message`.** The code is
               a stable machine string; the message is localized prose meant for
               display.
            5. **Log `meta.request_id`** (also returned as the `X-Request-Id`
               header) on every failure. Production runs with debug off, so this
               id is how a bug report gets matched to a server log line.

            ## Response shape

            Success responses always wrap the payload:

            ```json
            { "data": { ... }, "meta": { "request_id": "...", "api_version": "1" } }
            ```

            List endpoints add `meta.pagination` and `links`. Errors always look
            like:

            ```json
            {
              "error": { "code": "validation_failed", "message": "...", "details": { "field": ["..."] } },
              "meta": { "request_id": "...", "api_version": "1" }
            }
            ```

            ### Error codes you must handle

            | HTTP | code | Meaning |
            |------|------|---------|
            | 401 | `unauthenticated` | No/invalid/expired token — send the user to the login screen |
            | 403 | `forbidden` | The user lacks the permission. Hide the action rather than retrying |
            | 403 | `insufficient_token_ability` | The token was issued with a narrower scope — sign in again |
            | 404 | `not_found` | Unknown endpoint or record |
            | 409 | `conflict` | A request with the same Idempotency-Key is still in flight. Wait and poll |
            | 422 | `validation_failed` | Check `error.details` for per-field messages |
            | 422 | `business_rule_violated` | The payload was fine but the business state said no. `message` is safe to show the user as-is |
            | 429 | `rate_limited` | Back off for the number of seconds in `Retry-After` |

            ## Conventions

            - **Money and quantities are JSON strings** (`"1234.50"`), not
              numbers. Parse them with a decimal type; using a double will
              introduce rounding errors in financial totals.
            - **Timestamps are ISO-8601 UTC** (`2026-08-05T09:30:00Z`); dates are
              `2026-08-05`.
            - **Enums are objects**: `{"value": "in_progress", "label": "قيد التنفيذ", "color": "primary"}`.
              Branch on `value`, render `label`, and use `color` to match the web
              panel's badge colours. Fetch the whole catalog once from
              `GET /meta/enums` instead of hard-coding options.
            - **Language:** send `Accept-Language: ar` or `en`. It controls
              validation messages, business-rule messages, and every enum label.
            - **Lists** accept `page`, `per_page` (max 100), `sort`, `search`,
              `filter[...]` and `include`. An unknown filter/sort/include key is
              rejected with 422 listing what is allowed — nothing is silently
              ignored.
            - **Detail reads return an `ETag`.** Send it back as `If-None-Match`
              to get a `304 Not Modified` with an empty body instead of the full
              payload. On a weak link this is a large saving.

            ## Generating a Dart client

            The OpenAPI 3 spec for this API is published alongside this page at
            `openapi.yaml`. Point `openapi-generator` or `swagger_parser` at it
            to generate typed Dart models and a client, rather than writing them
            by hand.
        INTRO,

    // The base URL displayed in the docs.
    // If you're using `laravel` type, you can set this to a dynamic string, like '{{ config("app.tenant_url") }}' to get a dynamic base URL.
    // The production origin, NOT config('app.url'). These docs are generated
    // locally and committed, so APP_URL here would be a developer's localhost
    // and would ship as the documented Base URL. See config/api.php.
    // Origin ONLY — no '/api/v1' suffix. Scribe appends each route's FULL path
    // ("api/v1/meta") to this value, so including the version prefix here
    // produces doubled example URLs like /api/v1/api/v1/meta. The intro text
    // tells the reader every endpoint is relative to /api/v1.
    'base_url' => config('api.docs.public_url'),

    // Routes to include in the docs
    'routes' => [
        [
            'match' => [
                // Match only routes whose paths match this pattern (use * as a wildcard to match any characters). Example: 'users/*'.
                'prefixes' => ['api/v*'],

                // Match only routes whose domains match this pattern (use * as a wildcard to match any characters). Example: 'api.*'.
                'domains' => ['*'],
            ],

            // Include these routes even if they did not match the rules above.
            'include' => [
                // 'users.index', 'POST /new', '/auth/*'
            ],

            // Exclude these routes even if they matched the rules above.
            'exclude' => [
                // 'GET /health', 'admin.*'
            ],
        ],
    ],

    // The type of documentation output to generate.
    // - "static" will generate a static HTMl page in the /public/docs folder,
    // - "laravel" will generate the documentation as a Blade view, so you can add routing and authentication.
    // - "external_static" and "external_laravel" do the same as above, but pass the OpenAPI spec as a URL to an external UI template
    'type' => 'static',

    // See https://scribe.knuckles.wtf/laravel/reference/config#theme for supported options
    'theme' => 'default',

    'static' => [
        // HTML documentation, assets and Postman collection will be generated to this folder.
        // Source Markdown will still be in resources/docs.
        'output_path' => 'public/api/docs',
    ],

    'laravel' => [
        // Whether to automatically create a docs route for you to view your generated docs. You can still set up routing manually.
        'add_routes' => false,

        // URL path to use for the docs endpoint (if `add_routes` is true).
        // By default, `/docs` opens the HTML page, `/docs.postman` opens the Postman collection, and `/docs.openapi` the OpenAPI spec.
        'docs_url' => '/docs',

        // Directory within `public` in which to store CSS and JS assets.
        // By default, assets are stored in `public/vendor/scribe`.
        // If set, assets will be stored in `public/{{assets_directory}}`
        'assets_directory' => null,

        // Middleware to attach to the docs endpoint (if `add_routes` is true).
        'middleware' => [],
    ],

    'external' => [
        'html_attributes' => [],
    ],

    'try_it_out' => [
        // Add a Try It Out button to your endpoints so consumers can test endpoints right from their browser.
        // Don't forget to enable CORS headers for your endpoints.
        'enabled' => true,

        // The base URL to use in the API tester. Leave as null to be the same as the displayed URL (`scribe.base_url`).
        'base_url' => null,

        // [Laravel Sanctum] Fetch a CSRF token before each request, and add it as an X-XSRF-TOKEN header.
        'use_csrf' => false,

        // The URL to fetch the CSRF token from (if `use_csrf` is true).
        'csrf_url' => '/sanctum/csrf-cookie',
    ],

    // How is your API authenticated? This information will be used in the displayed docs, generated examples and response calls.
    'auth' => [
        // Set this to true if ANY endpoints in your API use authentication.
        'enabled' => true,

        // Set this to true if your API should be authenticated by default. If so, you must also set `enabled` (above) to true.
        // You can then use @unauthenticated or @authenticated on individual endpoints to change their status from the default.
        'default' => true,

        // Where is the auth value meant to be sent in a request?
        'in' => AuthIn::BEARER->value,

        // The name of the auth parameter (e.g. token, key, apiKey) or header (e.g. Authorization, Api-Key).
        'name' => 'Authorization',

        // The value of the parameter to be used by Scribe to authenticate response calls.
        // This will NOT be included in the generated documentation. If empty, Scribe will use a random value.
        'use_value' => env('SCRIBE_AUTH_KEY'),

        // Placeholder your users will see for the auth parameter in the example requests.
        // Set this to null if you want Scribe to use a random value as placeholder instead.
        'placeholder' => '{ACCESS_TOKEN}',

        // Any extra authentication-related info for your users. Markdown and HTML are supported.
        'extra_info' => 'Obtain a token from <code>POST /auth/login</code> with your email, password and a device name. '
            .'The plain-text token is returned <b>once</b> — store it in secure storage (e.g. <code>flutter_secure_storage</code>), never in shared preferences. '
            .'Tokens expire; rotate them with <code>POST /auth/refresh</code> before <code>expires_at</code>. '
            .'A token can be issued with narrowed abilities (e.g. <code>["inventory"]</code>) so a shared warehouse tablet cannot reach finance endpoints even if its account could.',
    ],

    // Example requests for each endpoint will be shown in each of these languages.
    // Supported options are: bash, javascript, php, python
    // To add a language of your own, see https://scribe.knuckles.wtf/laravel/advanced/example-requests
    // Note: does not work for `external` docs types
    'example_languages' => [
        'bash',
        'javascript',
    ],

    // Generate a Postman collection (v2.1.0) in addition to HTML docs.
    // For 'static' docs, the collection will be generated to public/docs/collection.json.
    // For 'laravel' docs, it will be generated to storage/app/scribe/collection.json.
    // Setting `laravel.add_routes` to true (above) will also add a route for the collection.
    'postman' => [
        'enabled' => true,

        'overrides' => [
            // 'info.version' => '2.0.0',
        ],
    ],

    // Generate an OpenAPI spec in addition to docs webpage.
    // For 'static' docs, the collection will be generated to public/docs/openapi.yaml.
    // For 'laravel' docs, it will be generated to storage/app/scribe/openapi.yaml.
    // Setting `laravel.add_routes` to true (above) will also add a route for the spec.
    'openapi' => [
        'enabled' => true,

        // The OpenAPI spec version to generate. Supported versions: '3.0.3', '3.1.0'.
        // OpenAPI 3.1 is more compatible with JSON Schema and is becoming the dominant version.
        // See https://spec.openapis.org/oas/v3.1.0 for details on 3.1 changes.
        'version' => '3.0.3',

        'overrides' => [
            // 'info.version' => '2.0.0',
        ],

        // Additional generators to use when generating the OpenAPI spec.
        // Should extend `Knuckles\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator`.
        'generators' => [],
    ],

    'groups' => [
        // Endpoints which don't have a @group will be placed in this default group.
        'default' => 'Endpoints',

        // By default, Scribe will sort groups alphabetically, and endpoints in the order their routes are defined.
        // You can override this by listing the groups, subgroups and endpoints here in the order you want them.
        // See https://scribe.knuckles.wtf/blog/laravel-v4#easier-sorting and https://scribe.knuckles.wtf/laravel/reference/config#order for details
        // Note: does not work for `external` docs types
        'order' => [],
    ],

    // Custom logo path. This will be used as the value of the src attribute for the <img> tag,
    // so make sure it points to an accessible URL or path. Set to false to not use a logo.
    // For example, if your logo is in public/img:
    // - 'logo' => '../img/logo.png' // for `static` type (output folder is public/docs)
    // - 'logo' => 'img/logo.png' // for `laravel` type
    'logo' => false,

    // Customize the "Last updated" value displayed in the docs by specifying tokens and formats.
    // Examples:
    // - {date:F j Y} => March 28, 2022
    // - {git:short} => Short hash of the last Git commit
    // Available tokens are `{date:<format>}` and `{git:<format>}`.
    // The format you pass to `date` will be passed to PHP's `date()` function.
    // The format you pass to `git` can be either "short" or "long".
    // Note: does not work for `external` docs types
    'last_updated' => 'Last updated: {date:F j, Y}',

    'examples' => [
        // Set this to any number to generate the same example values for parameters on each run,
        'faker_seed' => 1234,

        // With API resources and transformers, Scribe tries to generate example models to use in your API responses.
        // By default, Scribe will try the model's factory, and if that fails, try fetching the first from the database.
        // You can reorder or remove strategies here.
        'models_source' => ['factoryCreate', 'factoryMake', 'databaseFirst'],
    ],

    // The strategies Scribe will use to extract information about your routes at each stage.
    // Use configureStrategy() to specify settings for a strategy in the list.
    // Use removeStrategies() to remove an included strategy.
    'strategies' => [
        'metadata' => [
            ...Defaults::METADATA_STRATEGIES,
        ],
        'headers' => [
            ...Defaults::HEADERS_STRATEGIES,
            Strategies\StaticData::withSettings(data: [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
        ],
        'urlParameters' => [
            ...Defaults::URL_PARAMETERS_STRATEGIES,
        ],
        'queryParameters' => [
            ...Defaults::QUERY_PARAMETERS_STRATEGIES,
        ],
        'bodyParameters' => [
            ...Defaults::BODY_PARAMETERS_STRATEGIES,
        ],
        'responses' => configureStrategy(
            Defaults::RESPONSES_STRATEGIES,
            Strategies\Responses\ResponseCalls::withSettings(
                only: ['GET *'],
                // Recommended: disable debug mode in response calls to avoid error stack traces in responses
                config: [
                    'app.debug' => false,
                ]
            )
        ),
        'responseFields' => [
            ...Defaults::RESPONSE_FIELDS_STRATEGIES,
        ],
    ],

    // For response calls, API resource responses and transformer responses,
    // Scribe will try to start database transactions, so no changes are persisted to your database.
    // Tell Scribe which connections should be transacted here. If you only use one db connection, you can leave this as is.
    'database_connections_to_transact' => [config('database.default')],

    'fractal' => [
        // If you are using a custom serializer with league/fractal, you can specify it here.
        'serializer' => null,
    ],
];
