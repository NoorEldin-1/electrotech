# Electrotech API — Progress Tracker

> **Read this file first when resuming work in a new Claude Code session.**
> It is the single source of truth for *where we are*. The stable architecture
> and conventions live in [`API_Development_Plan.md`](API_Development_Plan.md);
> this file says what is built, what is next, and what was learned the hard way.
>
> Rule: update this file in the same change as the code. A progress file that
> lags the code is worse than no progress file.

---

## Status at a glance

| | |
|---|---|
| **Last updated** | 2026-08-05 |
| **Modules complete** | 1 of 11 |
| **Current module** | ✅ Module 1 — Foundation & Identity (DONE) |
| **Next module** | ⬜ Module 2 — Master Data & Files |
| **Tests** | 97 API tests passing (see § Test inventory) |
| **Docs** | Generated, committed at `public/api/docs/` |
| **Committed?** | ✅ Yes — committed and deployed to production on 2026-08-08 |

### Module board

| # | Module | Status | Endpoints | Tests |
|---|--------|--------|-----------|-------|
| 1 | **Foundation & Identity** | ✅ **Done** | 22 | 97 |
| 2 | Master Data & Files — Items, Customers, Suppliers, Attachments | ⬜ Not started | — | — |
| 3 | Sales & CRM — Projects, Offers, BOQ | ⬜ Not started | — | — |
| 4 | Technical Office / PMO — BOMs, Standard BOM | ⬜ Not started | — | — |
| 5 | Procurement — Purchase Orders, Reservations | ⬜ Not started | — | — |
| 6 | Inventory & Warehouse — levels, transactions, addition/depreciation vouchers | ⬜ Not started | — | — |
| 7 | Manufacturing & Material Movement — Work Orders, Quality Sheets, issue/return vouchers | ⬜ Not started | — | — |
| 8 | Delivery & Field Ops — delivery vouchers/minutes, installations, surveys | ⬜ Not started | — | — |
| 9 | Finance & Accounting — GL, journals, invoices, payments, claims | ⬜ Not started | — | — |
| 10 | Reports & Documents — trial balance, ledger, daybook, PDFs | ⬜ Not started | — | — |
| 11 | Cross-cutting — dashboard, notifications, activity log, search | ⬜ Not started | — | — |

Dependency order and the rationale for it are in `API_Development_Plan.md` §5.
**Do not start module N+1 while module N has an unticked box in §6 of the plan.**

---

## Module 1 — Foundation & Identity ✅

Everything a client needs before any business data exists: get a token, learn
who you are, learn what you may do, and learn the platform's vocabulary.

### Endpoints shipped (22)

**Public**
| Method | Path | Notes |
|---|---|---|
| GET | `/api/v1/meta` | Liveness + version + client config (TTL, page cap, abilities, docs URL) |
| POST | `/api/v1/auth/login` | Throttled 5/min per email+IP |

**Authenticated — self-scoped**
| Method | Path | Notes |
|---|---|---|
| POST | `/api/v1/auth/logout` | Revokes only the calling token |
| POST | `/api/v1/auth/logout-all` | Revokes every token |
| POST | `/api/v1/auth/refresh` | Rotates: new token issued, old revoked, abilities inherited |
| GET | `/api/v1/auth/me` | Profile + roles + full flat permission list |
| PATCH | `/api/v1/auth/profile` | Own name/email only |
| POST | `/api/v1/auth/change-password` | Requires current password; signs out other devices |
| GET | `/api/v1/auth/devices` | Active sessions; token value never returned |
| DELETE | `/api/v1/auth/devices/{device}` | Self-scoped revoke |
| GET | `/api/v1/meta/enums` | Every enum as `{value,label,color}`; `?only=` to narrow |

**Authenticated — identity administration** (`ability:identity`)
| Method | Path | Permission |
|---|---|---|
| GET | `/api/v1/users` | `users.view` |
| GET | `/api/v1/users/{user}` | `users.view` |
| POST | `/api/v1/users` | `users.create` |
| PATCH | `/api/v1/users/{user}` | `users.edit` |
| DELETE | `/api/v1/users/{user}` | `users.delete` |
| GET | `/api/v1/roles` | `roles.manage` |
| GET | `/api/v1/roles/{role}` | `roles.manage` |
| POST | `/api/v1/roles` | `roles.manage` |
| PATCH | `/api/v1/roles/{role}` | `roles.manage` |
| DELETE | `/api/v1/roles/{role}` | `roles.manage` |
| GET | `/api/v1/permissions` | `roles.manage` |

### Definition-of-done checklist

**Contract**
- [x] Routes registered in `routes/api/v1.php`
- [x] One `JsonResource` per entity (`UserResource`, `RoleResource`, `DeviceResource`)
- [x] One `FormRequest` per write endpoint; no `$request->all()` anywhere
- [x] Filters / sorts / includes explicitly whitelisted via `ApiQuery`
- [x] Enum fields emitted as `{value,label,color}`
- [x] Money/decimal fields as strings — *n/a for this module, no money fields*

**Behaviour**
- [x] Writes delegate to a service (`ApiTokenService`); no rules in controllers
- [x] `DomainException` → `422 business_rule_violated`
- [x] Every endpoint `authorize()`s against the existing policy
- [x] Detail GETs emit an `ETag`

**Performance**
- [x] No N+1 — asserted by a query-count test on `GET /users`
- [x] Index endpoints paginated, `per_page` cap enforced (422 over the cap)
- [x] `api-reports` limiter registered (no Module 1 endpoint needs it yet)

**Security**
- [x] `401` for unauthenticated — swept across all routes by `RouteConventionsTest`
- [x] `403` per permission-gated endpoint — one test per endpoint
- [x] Token-ability gate applied (`ability:identity` on administration routes)
- [x] No field in a resource that the panel would not show that role

**Tests** — `tests/Feature/Api/V1/`
- [x] Happy path per endpoint
- [x] Validation failure per write endpoint
- [x] RBAC denial per gated endpoint
- [x] Unauthenticated denial
- [x] Business-rule violation
- [x] Idempotency replay does not double-write
- [x] Pagination / filter / sort shape

**Docs**
- [x] Scribe annotations on every endpoint
- [x] Arabic/plain-language explanation of anything non-obvious
- [x] `php artisan scribe:generate` run, output committed
- [x] This file updated

---

## What was added to the codebase

### New dependencies
| Package | Type | Why |
|---|---|---|
| `laravel/sanctum` ^4.0 | **prod** | Bearer token auth with per-token abilities and server-side revocation |
| `knuckleswtf/scribe` ^5.11 | **dev** | Generates the docs page + `openapi.yaml` from controller annotations |

### New files

```
app/Http/Api/
├── ApiResponse.php          # the ONLY place a response body is shaped
├── ApiQuery.php             # whitelisted filter/sort/include/paginate
├── ApiRequestId.php         # correlation-id holder
└── EnumPresenter.php        # enum → {value,label,color}

app/Http/Middleware/
├── ApiRequestId.php         # mints/echoes X-Request-Id
├── ApiSecurityHeaders.php   # nosniff, DENY, no-referrer, HSTS, X-API-Version
├── ForceJsonResponse.php    # forces Accept: json; rejects non-JSON bodies (415)
├── RequireIdempotencyKey.php# 400 on a write with no Idempotency-Key
├── SetApiLocale.php         # Accept-Language → app locale + Content-Language
└── ConditionalGet.php       # ETag / If-None-Match → 304

app/Exceptions/Api/ApiExceptionRenderer.php   # every throwable → error envelope
app/Providers/ApiServiceProvider.php          # rate limiters + HTTPS in prod
app/Services/ApiTokenService.php              # issue / refresh / revoke / LRU cap
app/Http/Controllers/Api/V1/                  # ApiController base + 6 controllers
app/Http/Requests/Api/V1/                     # 6 FormRequests
app/Http/Resources/Api/V1/Identity/           # User, Role, Device resources
app/Http/Controllers/ApiDocsController.php    # serves /api/docs

config/api.php                                # every knob, all env-overridable
routes/api.php + routes/api/v1.php
tests/Feature/Api/V1/                         # harness + 4 suites
resources/views/vendor/scribe/                # published theme (OG/Twitter card)
public/api/docs/                              # GENERATED, COMMITTED
```

### Modified files
| File | Change |
|---|---|
| `bootstrap/app.php` | `api:` routing, API middleware stack, Sanctum ability aliases, API exception renderer |
| `bootstrap/providers.php` | registered `ApiServiceProvider` |
| `app/Models/User.php` | added `HasApiTokens` |
| `app/Http/Middleware/Idempotency.php` | **fixed caller scoping** — see Finding #3 |
| `routes/web.php` | `/api/docs` route |
| `routes/console.php` | daily `sanctum:prune-expired` |
| `config/permission.php` | cache store made env-overridable — see Finding #8 |
| `phpunit.xml` | `PERMISSION_CACHE_STORE=array` for test isolation |
| `lang/{en,ar}/errors.php` | `errors.api.*` message block |
| `deploy.sh` | docs verification + token pruning |
| `.env.example` | documented API env block |
| `config/scribe.php` | static output to `public/api/docs`, auth, intro text |

### NOT changed, deliberately
- **No new permissions.** Module 1 reuses `users.*` and `roles.manage`. There is
  deliberately **no `api.access` permission**: `RoleAndPermissionSeeder` only
  applies defaults to *newly created* roles, so a new gate permission would
  lock every existing role out of the API on the first deploy.
- **No `provider` on the sanctum guard** in `config/auth.php` — see Finding #2.
- **No sync layer.** The deleted `/sync/*` backend stays deleted
  (`API_Development_Plan.md` §1.3).

---

## Findings — bugs and traps discovered while building this

These cost real debugging time. Read them before Module 2.

### 1. The test harness hid auth bugs (fixed in `ApiTestCase`)
Laravel's `AuthManager` caches the resolved user on the guard for the lifetime
of a test. A second request in the same test reused the first request's user
**even with a revoked, expired, or garbage token** — so token revocation and
ability bugs passed every test. `ApiTestCase::apiJson()` now calls
`$this->app['auth']->forgetGuards()` before every request.
**Any new API test must go through `apiJson`/`apiGet`/`apiPost`, never raw `$this->json()`.**

### 2. `auth:sanctum` rewrites the default guard, breaking Spatie relations
`auth:sanctum` calls `Auth::shouldUse('sanctum')`, which sets
`config('auth.defaults.guard') = 'sanctum'` for the rest of the request.
Sanctum registers that guard with `provider => null`. Spatie's `Role::users()`
resolves its related model via `getModelForGuard(config('auth.defaults.guard'))`
→ **null → fatal**. So `Role::query()->withCount('users')` 500s inside any API
request.

**The obvious fix is a trap.** Giving the sanctum guard `provider => 'users'`
would make Spatie resolve User's default guard name to `'sanctum'` during API
requests, while every permission row is stored under guard `'web'` — silently
breaking *all* permission checks.

**Correct fix:** do not depend on the default guard. `RoleController::baseQuery()`
counts straight off the `model_has_roles` pivot. Any future endpoint touching
`Role::users()` or `Permission::users()` must do the same.

### 3. Idempotency scoping was ineffective (security fix)
`Idempotency` is a **global** middleware, so it runs before any route middleware
has authenticated anyone. `$request->user()` therefore returned `null` and every
request keyed as `u=guest` — collapsing all callers into one shared scope. Its
own comment claimed "Scope by user so one tenant cannot replay another's
response"; that isolation did not exist. One caller could have read another's
cached response body by reusing their `Idempotency-Key`.

Fixed by fingerprinting the bearer token (available unparsed at that point),
with the resolved-user fallback kept for the panel. Locked in by
`ApiContractTest::test_one_caller_cannot_replay_another_callers_cached_response`.

### 4. Validation ran before authorization (information disclosure)
With the policy check only in the controller, a caller lacking `users.create`
who posted an empty body got a **422 listing every field and rule** — an
endpoint they may not use telling them exactly how to use it. Laravel runs
`FormRequest::authorize()` *before* validation, so the policy check now lives
there too. **Every write FormRequest in future modules must implement
`authorize()`**, not rely on the controller alone.

### 5. ETags could never match
`ConditionalGet` hashed the whole response body, which includes the
per-request `meta.request_id` — so no two responses ever shared an ETag and
`If-None-Match` could never hit. The hash now excludes `meta.request_id`.

### 6. `Cache-Control: no-store` contradicted conditional GET
`no-store` forbids the client from keeping a copy at all, which makes
`If-None-Match` impossible. Changed to `private, no-cache`: still never cached
by a shared proxy, but the client may hold a copy and revalidate — which is the
whole point of the ETag layer on a weak link.

### 7. The throttle response was being turned into a 500
Laravel's throttle middleware wraps its response in `HttpResponseException`.
The API exception renderer did not recognise that type and rendered it as an
unhandled `server_error`, so **every rate-limited request returned 500 instead
of 429**. The renderer now passes an already-built JSON response through.

### 8. The test suite shared Spatie's permission cache with the dev app (fixed)
`config/permission.php` hardcoded `'store' => 'redis'`. That bypasses the
`CACHE_STORE=array` in `phpunit.xml`, so **the whole suite used one persistent,
process-external Redis cache — shared with the developer's running dev app —
while its own database was torn down and rebuilt between every test.** Cache and
database drift, and `RoleAndPermissionSeeder` starts throwing
`PermissionDoesNotExist` for permissions that are plainly in the table.

This was latent: it only surfaced once Module 1's tests began creating and
deleting roles, which rebuilt the shared cache from a half-seeded state and left
it poisoned — after which even a single unrelated test file failed 24 of 30
cases in isolation.

Fixed by making the store overridable — `env('PERMISSION_CACHE_STORE', 'redis')`
— and setting it to `array` in `phpunit.xml`. Production behaviour is unchanged.
**If tests ever start failing with `PermissionDoesNotExist`, check this first.**

### 9. The docs page rendered unstyled at `/api/docs` (fixed)
Scribe's static output references its own CSS/JS/images relatively
(`./css/...`). That only resolves when the URL ends in a slash or in
`index.html`. At the shareable URL we hand out — `/api/docs`, no trailing slash
— the browser resolved them against `/api/`, every asset 404'd, and the page
rendered as an unstyled wall of text.

Fixed in the published theme by overriding `$assetPathPrefix` to an absolute
`/api/docs/`, derived from `config('api.docs.path')` so the two cannot drift.
Works identically at `/api/docs`, `/api/docs/` and `/api/docs/index.html`, and
does not depend on the web server redirecting directory URLs. Locked in by
`ApiDocsTest`.

**Re-run `php artisan scribe:generate` and commit `public/api/docs/` after any
annotation change** — the output is build product committed to the repo.

### 10. The committed docs advertised `localhost` as the API host (fixed)
`config/scribe.php` and the theme's OG tags derived their URLs from
`config('app.url')`. The docs are generated on a developer machine and
**committed**, then served unchanged from production — so APP_URL froze
`http://localhost:8001` into the documented Base URL, the OpenAPI `servers`
entry, every curl example, the canonical link and the link-preview card.

This is invisible to whoever generates it (localhost works fine on their
laptop) and only breaks for everyone else, so it cannot be caught by looking.

Fixed with `config('api.docs.public_url')` — pinned to
`https://app.electrotech.findosystem.com`, env-overridable via
`API_DOCS_PUBLIC_URL` for staging, and **never** derived from APP_URL.

A second bug surfaced in the same place: `base_url` must be the **origin only**.
Scribe appends each route's full path (`api/v1/meta`), so including the version
prefix produced `/api/v1/api/v1/meta` in every example.

Both are locked in by `ApiDocsTest` — including a scan asserting the strings
`localhost` and `127.0.0.1` appear nowhere in the committed `index.html`,
`openapi.yaml` or `collection.json`.

### 11. Doc examples published real account addresses (fixed)
The `@bodyParam`/`@response` examples used `warehouse@electrotech.com` and
`admin@electrotech.com` — both **real accounts**. The docs are a public page
that documents the login endpoint, so this published confirmed-valid usernames
to anyone opening the link; an attacker would then need only the password.
(Nothing leaked from the database — Scribe made no live response calls. These
were hand-written examples that happened to name real accounts.)

Replaced with `@example.com` (RFC 2606's reserved documentation domain) and
guarded by `ApiDocsTest::test_the_docs_use_reserved_example_addresses_not_real_accounts`.

### 12. `config/scribe.php` would have taken the entire site down in production
**The most dangerous bug of this round — caught in a pre-deploy check, never shipped.**

Scribe's published config opens with `use Knuckles\Scribe\Config\AuthIn;` and
evaluates `AuthIn::BEARER->value`, `Defaults::METADATA_STRATEGIES`, etc. inside
the returned array. Scribe is a **`require-dev`** package and production runs
`composer install --no-dev`.

Laravel's `LoadConfiguration` bootstrapper `require()`s **every** file in
`config/` on **every** request. So on production the first request would hit:

    Error: Class "Knuckles\Scribe\Config\AuthIn" not found

That is not a broken artisan command — it is a fatal on every request: admin
panel, API, everything. In practice `php artisan config:cache` inside
`deploy.sh` dies first, the deploy aborts, and the app is left in maintenance
mode.

**Fix:** an early `if (! class_exists(AuthIn::class)) { return []; }` guard,
placed after the `use` statements (imports never autoload) and before any class
is touched. Locally the package is present and the full config is returned;
in production the file is inert, which is correct — Scribe's service provider is
not registered there either.

Verified with a `--no-dev` simulation that unregisters Composer's autoloader and
replaces it with one that *declines* the `Knuckles\` namespace. The first
attempt at that simulation *threw* from the autoloader instead of declining,
which wrongly made even the correct guard appear to fail — a real autoloader
declines, which is exactly why `class_exists()` can return `false`.

**Rule for future modules: no config file may reference a `require-dev` class
without such a guard.** `bootstrap/cache/packages.php` is untracked, so the
service-provider manifest regenerates without Scribe on the server — that part
was already safe.

### 13. `/docs` was already taken
`routes/web.php` redirects `/docs` → `/documentation` (the Arabic end-user
manual). The API reference therefore lives at **`/api/docs`**. Do not merge them
— different documents, different audiences.

---

## Version control — what is committed and why

No `.gitignore` change is needed. Two of the new paths look like build output
but **must** be committed:

| Path | Committed? | Why |
|---|---|---|
| `public/api/docs/` | **Yes — required** | Scribe is a `require-dev` package and production installs with `--no-dev`, so the server *cannot* build these. If they are ignored, `/api/docs` 404s in production. `deploy.sh` verifies they are present. |
| `.scribe/` | **Yes** | Scribe's own source of truth, not a throwaway cache. `.scribe/endpoints/*.yaml` are hand-editable — you can override an extracted example there and it survives regeneration. Scribe's documentation says to commit the folder. ~150 KB. |
| `config/sanctum.php`, `config/scribe.php` | Yes | Published vendor config |
| `resources/views/vendor/scribe/` | Yes | Our customised theme (absolute asset paths, OG card) |
| `database/migrations/*_create_personal_access_tokens_table.php` | Yes | Sanctum's token table |

Verified with `git check-ignore` that no existing rule silently excludes any of
them — an ignored `public/api/docs/` would produce a docs link that 404s with
nothing failing anywhere else.

---

## Deployment

### What changed in `deploy.sh`
1. **Docs verification** (new step, before cache clearing). Scribe is
   `require-dev` and production installs `--no-dev`, so docs **cannot** be
   generated on the server. They are generated locally and committed. The step
   warns loudly if `public/api/docs/index.html` is missing but does **not** fail
   the deploy — missing documentation must never block shipping code.
2. **`sanctum:prune-expired --hours=24`** (new step, after queue restart).
   Also scheduled daily in `routes/console.php`; running it on deploy means the
   table is cleaned even if the server cron for `schedule:run` is not set up.

### What did NOT need changing, and why
- **Migrations** — `php artisan migrate --force` already runs; it picks up
  Sanctum's `personal_access_tokens` table automatically.
- **`route:cache`** — already runs. API routes are cacheable because none of
  them is a closure; `RouteConventionsTest` enforces that so a future module
  cannot silently break the deploy.
- **`config:cache`** — already runs, picks up `config/api.php` and
  `config/sanctum.php`.
- **Permission seeding** — already runs; no new permissions were added.
- **CI (`.github/workflows/deploy.yml`)** — unchanged. It only opens an SSH
  session and runs `deploy.sh`.

### Production verification — 2026-08-08 (commit `42dad4f`, deploy run #32)

Deployed via GitHub Actions in 48s. Deploy log confirmed: the
`personal_access_tokens` migration ran, the committed docs were found
(172,863 bytes), `config:cache` and `route:cache` succeeded, and expired-token
pruning ran.

`config:cache` succeeding is the meaningful signal: without the
`config/scribe.php` guard (Finding #12) that exact step would have fataled and
left the site in maintenance mode.

| Check | Result |
|---|---|
| `GET /api/v1/meta` | 200, correct envelope, `default_locale: ar` |
| Security headers | `nosniff`, `DENY`, `no-referrer`, HSTS present over HTTPS |
| `X-API-Version` / `X-Request-Id` | present, header matches `meta.request_id` |
| 401 / 404 / 405 envelopes | correct codes, `allowed_methods` on the 405 |
| Write without `Idempotency-Key` | 400 `bad_request` |
| Login validation | 422 with per-field `details` |
| `Accept-Language: en` | English message; Arabic by default |
| ETag → `If-None-Match` | 304 Not Modified |
| Login rate limit | 5 × 422 then 429 with `Retry-After: 56` |
| Invalid bearer token | 401 (not 500 — token table present and queried) |
| `/api/docs` | 301 → `/api/docs/` → 200, all CSS/JS/images 200 |
| Docs content | Base URL and every example on the production host; **0** occurrences of `localhost` |
| `openapi.yaml` / `collection.json` | 200, `servers.url` = production origin |
| OG / Twitter card | present, `summary_large_image` |
| Admin panel `/`, `/admin/login`, `/documentation`, `/up` | all 200 — unaffected |
| Legacy `/docs` | still 301 → `/documentation` (Arabic manual), no collision |

**Still unverified:** an authenticated round-trip (login → token → `/auth/me` →
logout) against production, which needs a real credential. Every layer around it
is proven; the token lookup itself is exercised by the invalid-token 401.

### Server checklist (one-time, on `app.electrotech.findosystem.com`)
- [x] Confirm `APP_URL=https://app.electrotech.findosystem.com` in the server's
      `.env` — it is the `base_url` printed in the docs and the OpenAPI
      `servers` entry, and it is what makes the OG card resolve absolute URLs.
- [x] Confirm `APP_DEBUG=false` — the API's 500 handler only suppresses details
      when debug is off.
- [ ] Optionally set `API_DOCS_TWITTER_SITE=@handle` for the link-preview card.
- [ ] Confirm the cron entry for `php artisan schedule:run` exists (needed by
      the pre-existing daily sales job as well as token pruning).
- [x] Verify `https://app.electrotech.findosystem.com/api/docs` loads and
      `.../api/docs/openapi.yaml` downloads.

---

## Test inventory

`php artisan test tests/Feature/Api` — **96 tests**

| Suite | Tests | Covers |
|---|---|---|
| `Foundation/ApiContractTest` | 23 | Envelope shape, headers, request id, 404/405/415, idempotency (incl. cross-caller isolation), ETag/304, rate limits, locale negotiation, enum catalog, no stack-trace leak |
| `Foundation/ApiDocsTest` | 9 | Docs page reachable, assets referenced absolutely, generated files present, OG card, OpenAPI base URL |
| `Foundation/RouteConventionsTest` | 6 | Every route: authenticated, throttled, named, versioned, no closures; public-route exemption list kept honest |
| `Auth/AuthenticationTest` | 16 | Login, credential-enumeration resistance, roleless account, token expiry, logout/logout-all, rotation, ability narrowing, LRU eviction |
| `Auth/ProfileAndDeviceTest` | 13 | me/profile/password, privilege-escalation guard, device list/revoke, cross-user revoke |
| `Identity/UserManagementTest` | 17 | CRUD, pagination/filter/sort/search, N+1 guard, RBAC sweep, admin password reset revokes sessions |
| `Identity/RoleAndPermissionTest` | 16 | Role CRUD, permission catalog, cache invalidation, Admin-role protection, i18n labels |

### Commands
```bash
# API tests only
php artisan test tests/Feature/Api

# Everything (the full suite is slow — several minutes)
php artisan test

# Regenerate docs after touching any annotation, then COMMIT public/api/docs/
php artisan scribe:generate
```

---

## Starting Module 2 — a concrete recipe

Module 2 is **Master Data & Files**: Items, Customers, Suppliers, and a generic
Attachments endpoint. Everything from Module 3 onward references these.

1. Read `API_Development_Plan.md` §3 (the contract) and §6 (definition of done).
2. Re-read **Findings #2 and #4** above — both will bite in Module 2.
3. For each entity:
   - `app/Http/Resources/Api/V1/MasterData/<Entity>Resource.php` — explicit
     field list; money as strings; enums via `EnumPresenter::present()`.
   - `app/Http/Requests/Api/V1/MasterData/{Store,Update}<Entity>Request.php` —
     with `authorize()` implemented.
   - `app/Http/Controllers/Api/V1/MasterData/<Entity>Controller.php` extending
     `ApiController`; index via `ApiQuery` with explicit whitelists;
     `$this->authorize()` on every action; writes delegate to the existing
     service where one exists.
   - Routes under a `Route::middleware('ability:master-data')` group in
     `routes/api/v1.php`, split between `throttle:api-read` and
     `throttle:api-write`.
   - `tests/Feature/Api/V1/MasterData/<Entity>Test.php` extending `ApiTestCase`.
4. Attachments need care: uploads are `multipart/form-data` (already allowed by
   `ForceJsonResponse`), must reuse `App\Services\EntityAttachmentPersistence`,
   and downloads must stream through a policy-gated controller — never a public
   URL.
5. Run `php artisan test tests/Feature/Api`, then `php artisan scribe:generate`,
   then update this file's module board and add a Module 2 section mirroring
   Module 1's.

---

## Pre-existing failures found (NOT caused by this work, NOT fixed)

Three tests were already failing on `main` before any API code existed. They are
listed here so the next session does not waste time re-diagnosing them, and so
nobody mistakes them for API regressions. Each needs a product decision, so none
was touched.

| Test | Symptom | Cause |
|---|---|---|
| `NetworkResilienceTest::test_large_response_is_compressed_for_gzip_client` | Expects `Content-Encoding: gzip`, gets none | `App\Http\Middleware\CompressResponse` is **imported in `bootstrap/app.php` but never appended to the stack**. The comment block describes it as step 3 of the resilience chain; the `$middleware->append()` call is missing. Compression is off in production. |
| `NetworkResilienceTest::test_ping_endpoint_is_auth_gated_but_csrf_exempt` | Expects `401` unauthenticated, gets `200` | The `/admin/ping` route's comment says it "Uses Laravel's standard `auth` middleware", but the middleware array in `routes/web.php` lists only cookie/session/binding middleware — **`auth` is not there**. The endpoint is currently open to anonymous callers. |
| `ActivityLogTest::test_subject_type_is_translated_to_locale_label` | Expects `'Work Order'`, gets `'Manufacturing Order'` | The English label was deliberately renamed during the PMO round; the assertion was never updated. The test is stale, not the code. |

The ping one is the only one with a security edge (an unauthenticated endpoint
that returns `user_id` and touches the session). It is a one-line fix — adding
`'auth'` to that route's middleware list — but it changes behaviour outside the
API's scope, so it is left for an explicit decision.

---

## Open questions / deferred decisions

| Item | Decision | Revisit when |
|---|---|---|
| Password reset by email | Not implemented — the platform has `MAIL_MAILER=log` and no reset flow in the panel either. Administrators reset passwords via `PATCH /users/{user}`. | Real SMTP is configured |
| Refresh-token pair (separate long-lived credential) | Not implemented. The access token rotates itself via `/auth/refresh`, which is simpler and revocable. | A client genuinely needs offline-for-months sessions |
| Push notifications to the Flutter app | Out of scope for the API modules; belongs with Module 11 (notifications) | Module 11 |
| `updated_after` as a real delta-sync protocol | Deliberately **not** built. It is a plain `updated_at >` filter; the server holds no sync state. See plan §1.3. | Never — the sync layer was removed on purpose |
| API versioning to v2 | Not needed. Additive changes are non-breaking by the contract in plan §3.1 | First genuinely breaking change |
