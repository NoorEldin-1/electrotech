# Electrotech — REST API Development Plan

> **Purpose.** Expose the entire Electrotech ERP over a versioned, documented, secured
> HTTP API so a Flutter mobile application (and any future client) can drive the same
> business processes the Filament admin panel drives today.
>
> **Production host:** `https://app.electrotech.findosystem.com`
> **API base URL:** `https://app.electrotech.findosystem.com/api/v1`
> **Docs URL:** `https://app.electrotech.findosystem.com/api/docs` (public)
> **OpenAPI spec:** `https://app.electrotech.findosystem.com/api/docs/openapi.yaml`
>
> **Companion file:** [`API_PROGRESS.md`](API_PROGRESS.md) — the single living tracker.
> Read that first when resuming work in a new session. *This* file is the stable
> reference for architecture and conventions; the progress file says where we are.

---

## 1. Why this shape

### 1.1 What already exists (and what we reuse)

The codebase is in an unusually good position to grow an API, because the business
rules were never written into the UI layer:

| Existing asset | Count | How the API uses it |
| --- | --- | --- |
| `app/Services/*` | 28 services | **Reused verbatim.** All writes go through them. `SalesPipelineService` even documents this intent: *"the same rules apply whether a user clicks an Action button, an API caller hits an endpoint, or a future scheduled job triggers it."* |
| `app/Policies/*` | 34 policies | **Reused verbatim.** `$this->authorize()` in API controllers hits the same policies Filament hits. |
| Spatie permission catalog | ~190 permissions | **Reused verbatim.** The API adds *no* parallel permission system. |
| `app/Enums/*` | 30+ enums | Serialized into a `/meta/enums` catalog so Flutter builds dropdowns from the server. |
| `app/Http/Middleware/Idempotency.php` | 1 | **Reused verbatim** — it already implements `Idempotency-Key` replay protection for any `POST/PUT/PATCH/DELETE`. Written for the weak factory link; perfect for mobile. |
| `app/Models/*` factories | 35 factories | Test fixtures for API feature tests come for free. |
| `spatie/laravel-activitylog` | — | API writes are audited automatically through the models' `LogsActivity` trait. |

**Design rule #1 — the API is a *transport*, not a second brain.**
No business rule may be implemented in a controller. If a rule is missing, it goes in the
service and both the panel and the API get it.

### 1.2 What is genuinely new

- Token authentication (Sanctum) — the panel uses sessions; mobile cannot.
- A stable JSON contract: envelope, error codes, pagination, filtering.
- Rate limiting, ETag/conditional GET, request tracing.
- Generated documentation + OpenAPI spec.

### 1.3 Explicit non-goal — this is not the deleted sync layer

Commit `573f61b` removed the `/console/` PWA and the whole `/sync/*` backend
deliberately. **This API does not resurrect it.** There is no server-side change
journal, no conflict resolution, no push queue, no `sync_state` tables. The
`updated_after` query filter documented in §3.6 is an ordinary REST filter on
`updated_at` — the client may use it to refresh a local cache, but the server holds
no sync state and makes no delivery guarantees. Keep it that way.

---

## 2. Technology decisions

| Concern | Decision | Rationale |
| --- | --- | --- |
| **Auth** | `laravel/sanctum` ^4.0, personal access tokens (bearer) | Native, first-party, integrates with existing guards/policies with zero glue. Supports per-token *abilities* and server-side revocation — a JWT cannot be revoked without a blacklist. |
| **Versioning** | URI path — `/api/v1/...` | Unambiguous for a Flutter client, cache-friendly, trivially routable. Header/media-type versioning is invisible in logs and harder to debug in the field. |
| **Serialization** | Laravel API Resources (`JsonResource`) | One class per entity = one authoritative field list. Prevents accidental leakage of new DB columns. |
| **Query features** | In-house `App\Http\Api\ApiQuery` | ~180 explicit lines instead of a dependency. Every filter/sort/include must be whitelisted per endpoint; an unknown key is a `422`, never a silent no-op. Matches the codebase's explicit, heavily-commented style. |
| **Docs** | `knuckleswtf/scribe` ^5 (dev-dependency) + OpenAPI 3 export | Reads annotations from the controllers, so docs sit next to the code they describe. The `openapi.yaml` export lets the Flutter developer generate a typed Dart client instead of hand-writing models. |
| **Rate limiting** | Laravel `RateLimiter` on the Redis store | Redis is already the cache/session/queue driver in production. |
| **Idempotency** | Existing `Idempotency` middleware | Already built, already tested (`NetworkResilienceTest`). |
| **Transport** | JSON only, UTF-8, HTTPS enforced in production | |

---

## 3. The API contract

Everything in this section is **binding**. Any module that deviates is a bug.

### 3.1 Base URL and versioning

```
https://app.electrotech.findosystem.com/api/v1
```

- The major version changes **only** on a breaking change. Adding a field, adding an
  endpoint, adding an optional parameter, or relaxing validation is *not* breaking.
- Every response carries `X-API-Version: 1`.
- When `v2` ships, `v1` keeps serving and gains a `Sunset: <HTTP-date>` header plus a
  `Deprecation: true` header for at least one release cycle.
- `routes/api/v1.php` is a self-contained file. `v2` will be a sibling file, never an
  `if` inside a shared controller.

### 3.2 Response envelope

**Single resource** — `200` / `201`:

```json
{
  "data": { "id": 12, "type": "project", "...": "..." },
  "meta": { "request_id": "01JBX...", "api_version": "1" }
}
```

**Collection** — `200`:

```json
{
  "data": [ { "...": "..." } ],
  "meta": {
    "request_id": "01JBX...",
    "api_version": "1",
    "pagination": {
      "total": 137, "count": 25, "per_page": 25,
      "current_page": 2, "total_pages": 6
    }
  },
  "links": {
    "first": "...?page=1", "prev": "...?page=1",
    "next": "...?page=3", "last": "...?page=6"
  }
}
```

**No content** — `204` with an empty body (used by `DELETE` and by revoke-style actions).

Rules:
- `data` is **always** present on a success response that has a body. Never a bare array
  at the top level, never a bare scalar.
- `meta.request_id` mirrors the `X-Request-Id` response header. Ask the Flutter developer
  to log it — it is the only thing that makes a field bug report actionable.

### 3.3 Error envelope

Every non-2xx response, without exception:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "details": {
      "quantity": ["The quantity must be at least 1."]
    }
  },
  "meta": { "request_id": "01JBX...", "api_version": "1" }
}
```

`error.code` is a **stable machine-readable string** — the Flutter app branches on it,
never on `message` (which is localized and may change).

| HTTP | `error.code` | When |
| --- | --- | --- |
| 400 | `bad_request` | Malformed JSON body, bad `Idempotency-Key` format |
| 401 | `unauthenticated` | Missing/invalid/expired token |
| 401 | `invalid_credentials` | Login with wrong email/password |
| 403 | `forbidden` | Authenticated but the policy/permission denied it |
| 403 | `insufficient_token_ability` | The user *may*, but this token's abilities do not |
| 404 | `not_found` | Unknown route, or model binding miss |
| 405 | `method_not_allowed` | Wrong verb on a known path |
| 409 | `conflict` | Concurrent replay of the same `Idempotency-Key` |
| 415 | `unsupported_media_type` | Body sent as something other than JSON |
| 422 | `validation_failed` | `FormRequest` rejection |
| 422 | `business_rule_violated` | A service threw `DomainException` — the request was well-formed but the state machine says no |
| 429 | `rate_limited` | Throttle tripped; `Retry-After` header is set |
| 500 | `server_error` | Unhandled. `details` is omitted unless `APP_DEBUG` |
| 503 | `maintenance` | `php artisan down` during a deploy |

`business_rule_violated` is the one Flutter developers must handle carefully: it means
*"your payload was fine, the business state was not"* — e.g. finishing a work order
whose materials were never issued. The `message` is the human-readable Arabic/English
sentence produced by the service and is safe to show to the user directly.

### 3.4 Authentication

**Login** — `POST /api/v1/auth/login`

```json
{ "email": "user@electrotech.com", "password": "secret", "device_name": "Pixel 8 — Warehouse" }
```

Returns a plain-text bearer token (shown exactly once), its expiry, and the user profile
including the resolved permission list. Subsequent requests:

```
Authorization: Bearer 12|abcdef...
Accept: application/json
```

- Tokens expire after `API_TOKEN_TTL_MINUTES` (default **43200** = 30 days).
- `POST /api/v1/auth/refresh` rotates the current token: issues a new one and revokes
  the caller's current token in the same transaction. The client should call it when the
  token is within a few days of expiry.
- `device_name` is required so the user can review and revoke sessions from
  `GET /api/v1/auth/devices`.
- Login is throttled at **5 attempts / minute** keyed on `email + IP`.
- Expired tokens are pruned nightly (`sanctum:prune-expired`).

**Token abilities.** A token is a *narrowing* of the user, never a widening. The
effective permission set is `user_permissions ∩ token_abilities`. Default on login is
`["*"]` (full user rights). A caller may request a narrower set — useful for a
warehouse-only tablet. Both gates must pass:

```php
Route::middleware(['auth:sanctum', 'ability:inventory'])->group(...)
```

### 3.5 Authorization

Unchanged from the panel. The API calls the same policies:

```php
$this->authorize('update', $project);   // → ProjectPolicy::update → $user->can('projects.edit')
```

Adding an API endpoint **must not** add a permission unless the panel gained a genuinely
new capability. `GET /api/v1/permissions` exposes the catalog so a Flutter screen can
hide buttons the user cannot use — but the server is still the only gate.

### 3.6 Collections: pagination, filtering, sorting, includes

| Parameter | Example | Notes |
| --- | --- | --- |
| `page` | `?page=3` | 1-indexed |
| `per_page` | `?per_page=50` | Default **25**, hard cap **100**. Over the cap → `422`, not a silent clamp |
| `sort` | `?sort=-created_at,name` | `-` prefix = descending. Whitelisted per endpoint |
| `filter[...]` | `?filter[status]=in_progress` | Whitelisted per endpoint |
| `filter[...]` ranges | `?filter[created_between]=2026-01-01,2026-06-30` | |
| `search` | `?search=panel` | Endpoint declares which columns it spans |
| `include` | `?include=customer,latestOffer` | Whitelisted relations; drives eager loading. Prevents N+1 **and** prevents a client from eager-loading its way into a slow query |
| `updated_after` | `?updated_after=2026-08-01T10:00:00Z` | Plain `updated_at >` filter for cache refresh. **Not** a sync protocol — see §1.3 |

An unknown `filter`/`sort`/`include` key returns `422` listing the allowed keys. This is
deliberate: silent ignoring is how clients ship bugs that nobody notices for months.

### 3.7 Idempotency (required on every write)

Every `POST`/`PUT`/`PATCH`/`DELETE` **must** send:

```
Idempotency-Key: <uuid-v4>
```

The key is generated once per *logical user action* and reused across the client's own
retries. Behaviour is already implemented in `App\Http\Middleware\Idempotency`:

- first call → executes, caches `(status, headers, body)` for 24h
- replay → returns the cached response verbatim, **without re-executing**
- concurrent replay while the first is still running → `409 conflict`

This is what stops a warehouse tablet on a flaky link from posting the same issue
voucher twice. In `v1` a missing key on a write is answered with `400 bad_request`
when `API_REQUIRE_IDEMPOTENCY_KEY=true` (the production default).

### 3.8 Conditional GET (`ETag`)

Single-resource `GET`s return a strong `ETag`. A client resending `If-None-Match` gets
`304 Not Modified` with an empty body. On a slow connection this turns a 40 KB work-order
payload into a ~200-byte response. Applied to detail endpoints only; collections change
too often for it to pay off.

### 3.9 Rate limits

Keyed by authenticated user id, falling back to IP for guests. All limited responses
carry `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `Retry-After`.

| Limiter | Applies to | Limit |
| --- | --- | --- |
| `api-auth` | `POST /auth/login`, `/auth/forgot-password` | 5 / min per `email+IP` |
| `api-read` | All `GET` | 120 / min per user |
| `api-write` | `POST`/`PUT`/`PATCH`/`DELETE` | 40 / min per user |
| `api-reports` | Report + PDF endpoints (§ Module 10) | 10 / min per user |

Every limit is configurable in `config/api.php` via `.env`, so a real-world spike can be
absorbed without a code deploy.

### 3.10 Data formats

| Type | Format | Why |
| --- | --- | --- |
| Timestamps | ISO-8601 UTC — `2026-08-05T09:30:00Z` | `DateTime.parse()` in Dart handles it natively |
| Dates | `2026-08-05` | |
| Money / quantities | **JSON string** — `"1234.50"` | The DB uses `decimal:2`. Serializing as a JSON number invites Dart double-rounding on financial totals. Flutter parses with `Decimal.parse()` |
| Enums | Object — `{ "value": "in_progress", "label": "قيد التنفيذ" }` | The client renders `label` and branches on `value`; no client-side translation table to keep in sync |
| Booleans | `true` / `false` | Never `0`/`1` |
| Money currency | Implicit EGP | Single-currency system; documented, not repeated per field |
| Empty relation | `null` | Never `{}` or `0` |

### 3.11 Localization

`Accept-Language: ar` or `en` selects the language of `message`, validation errors, and
enum `label`s. Falls back to the app default. Reuses the existing `lang/{ar,en}` files —
no separate API translation tree.

### 3.12 Security posture

- HTTPS enforced in production (`URL::forceScheme('https')` when `APP_ENV=production`).
- **Stateless.** No session, no cookies, no CSRF on `/api/*` — the token is the only credential.
- `Strict-Transport-Security`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  `Referrer-Policy: no-referrer` on every API response via `ApiSecurityHeaders`.
- Login throttled and lockout-logged; token issue/revoke written to the activity log.
- Tokens hashed at rest (Sanctum default); the plain text is returned exactly once.
- Password changes revoke **all other** tokens for that user.
- `APP_DEBUG=false` in production → stack traces never leave the server; the
  `request_id` is the correlation handle instead.
- Uploads: extension + MIME allow-list, size cap, stored outside the webroot, served
  through a policy-gated controller — never a direct public URL.
- No mass assignment: every write goes through a `FormRequest` with an explicit rule set.
- Field-level exposure controlled by API Resources, so adding a DB column never silently
  publishes it.

---

## 4. Directory layout

```
app/
├── Http/
│   ├── Api/
│   │   ├── ApiQuery.php              # filter/sort/include/paginate engine
│   │   ├── ApiResponse.php           # envelope builder
│   │   └── EnumPresenter.php         # enum → {value,label}
│   ├── Controllers/Api/V1/
│   │   ├── ApiController.php         # base: authorize + respond helpers
│   │   ├── Auth/                     # Module 1
│   │   ├── Identity/                 # Module 1
│   │   ├── MasterData/               # Module 2
│   │   ├── Sales/                    # Module 3
│   │   └── ...                       # one folder per module
│   ├── Middleware/
│   │   ├── ApiRequestId.php
│   │   ├── ApiSecurityHeaders.php
│   │   ├── ForceJsonResponse.php
│   │   ├── RequireIdempotencyKey.php
│   │   └── SetApiLocale.php
│   ├── Requests/Api/V1/<Module>/     # one FormRequest per write endpoint
│   └── Resources/Api/V1/<Module>/    # one JsonResource per entity
├── Exceptions/Api/
│   └── ApiExceptionRenderer.php      # every throwable → the §3.3 envelope
routes/
├── api.php                           # loads the version files
└── api/v1.php                        # the whole v1 surface
config/
└── api.php                           # version, limits, pagination caps, docs
tests/Feature/Api/V1/
├── ApiTestCase.php                   # shared harness
├── Foundation/                       # contract tests (envelope, limits, ETag…)
└── <Module>/
```

---

## 5. Module order

Ordered so that **every module only depends on modules already shipped**. No module is
started before its dependencies are green.

| # | Module | Entities | Depends on |
| --- | --- | --- | --- |
| **1** | **Foundation & Identity** | Auth/tokens/devices, profile, users, roles, permissions, enum catalog, health | — |
| **2** | **Master Data & Files** | Items, Customers, Suppliers, Attachments (generic) | 1 |
| **3** | **Sales & CRM** | Projects (Tender→In-Hand→Active→Lost), Project Offers, BOQ groups/items, alarms | 1, 2 |
| **4** | **Technical Office / PMO** | BOMs, BOM Items, Standard BOM | 1, 2, 3 |
| **5** | **Procurement** | Purchase Orders + items, approve/receive, Stock Reservations, supplier statements | 1, 2, 3, 4 |
| **6** | **Inventory & Warehouse** | Inventory levels, Inventory Transactions, Stock Card, Addition Vouchers, Depreciation Vouchers | 1, 2, 5 |
| **7** | **Manufacturing & Material Movement** | Work Orders (+ materials, outputs, approval chain), Production Entries, Quality Sheets, **Issue Vouchers, Return Vouchers** | 1, 2, 3, 4, 6 |
| **8** | **Delivery & Field Ops** | Delivery Vouchers (dual approval), Delivery Minutes, Installations, Site Surveys | 1, 3, 6, 7 |
| **9** | **Finance & Accounting** | Chart of Accounts, Journal Entries + lines, Account Entries, Sales Invoices, Operation Payments, Financial Claims, Credit Facilities, Cost-Center Closing | 1–8 |
| **10** | **Reports & Documents** | Trial Balance, General Ledger, Journal Daybook, Operation Cost File, WO material variance, Supply Orders File, loss-value report + all PDF streams | 9 |
| **11** | **Cross-cutting** | Dashboard KPIs, notifications, activity log, global search | all |

### Why Issue/Return vouchers live in Module 7, not Module 6

They are warehouse documents, so instinct says Module 6. But an Issue Voucher is always
raised *against a Work Order* and — since commit `2baa372` — is validated against that
order's remaining material requirement, with an `issue_vouchers.approve_excess` gate.
Shipping it before Work Orders exist would mean shipping it untestable. Splitting the
warehouse module along the "needs a work order / doesn't" line is the only ordering that
keeps every module independently shippable and independently testable.

---

## 6. Definition of done (per module)

A module is not "done" until **every** box is ticked. This list is copied into each
module's section in `API_PROGRESS.md`.

**Contract**
- [ ] Routes registered in `routes/api/v1.php` under the module's prefix
- [ ] One `JsonResource` per entity; no model serialized directly
- [ ] One `FormRequest` per write endpoint; no `$request->all()` anywhere
- [ ] Filters / sorts / includes explicitly whitelisted
- [ ] Enum fields emitted as `{value, label}`
- [ ] Money/decimal fields emitted as strings

**Behaviour**
- [ ] All writes delegate to the existing `App\Services\*` service — no rules in controllers
- [ ] `DomainException` from services maps to `422 business_rule_violated`
- [ ] Every endpoint `authorize()`s against the existing policy
- [ ] Detail `GET`s emit an `ETag`

**Performance**
- [ ] No N+1: a test asserts the query count for the index endpoint
- [ ] Index endpoints paginated, cap enforced
- [ ] Expensive/report endpoints on the `api-reports` limiter

**Security**
- [ ] `401` for unauthenticated on every route (asserted by the foundation sweep test)
- [ ] `403` for a user lacking the permission — one test per permission-gated endpoint
- [ ] Token-ability gate applied where the module has a natural scope
- [ ] No field in the resource that the panel would not show that role

**Tests** (`tests/Feature/Api/V1/<Module>/`)
- [ ] Happy path per endpoint
- [ ] Validation failure per write endpoint (`422`, correct `details` keys)
- [ ] RBAC denial per gated endpoint (`403`)
- [ ] Unauthenticated denial (`401`)
- [ ] Business-rule violation (`422 business_rule_violated`) for each state-machine guard
- [ ] Idempotency replay returns the cached response and does **not** double-write
- [ ] Pagination/filter/sort shape

**Docs**
- [ ] Scribe annotations on every endpoint: `@group`, `@authenticated`, `@bodyParam`,
      `@queryParam`, `@response` with a realistic example
- [ ] Arabic explanation for anything a Flutter developer cannot infer from the field name
- [ ] `php artisan scribe:generate` re-run and output committed
- [ ] `API_PROGRESS.md` module section updated

---

## 7. Deployment impact

Full detail and the reasoning behind each change is in `API_PROGRESS.md § Deployment`.
Summary of what the API requires from `deploy.sh` / CI / environment:

1. **Migrations** — Sanctum's `personal_access_tokens` table. Covered by the existing
   `php artisan migrate --force` step. No action needed.
2. **`route:cache`** — the existing step now also caches `/api/*`. Requires that no API
   route uses a closure. Enforced by an architecture test.
3. **Token pruning** — `sanctum:prune-expired --hours=24` added to the scheduler.
   Requires the server cron to be calling `schedule:run` (already needed by the existing
   `sales:notify-incomplete-operations` daily job).
4. **Docs generation** — Scribe is a `require-dev` package and production installs with
   `--no-dev`, so docs **cannot** be generated on the server. They are generated locally
   and the output committed. `deploy.sh` gains a verification step that fails loudly if
   the committed docs are missing, rather than serving a 404 at `/api/docs`.
5. **`/docs` collision** — `routes/web.php` already redirects `/docs` → `/documentation`
   (the Arabic user manual). The API docs therefore live at **`/api/docs`**. Do not move
   either one.
6. **New `.env` keys** — added to `.env.example` with production-safe defaults; the
   server's `.env` needs them set once (documented in the progress file).

---

## 8. Working agreement for future sessions

1. Open `API_PROGRESS.md` first. It states the current module and the next concrete step.
2. Never start module *N+1* while module *N* has an unticked box in §6.
3. Run `php artisan test --testsuite=Feature` before ticking anything.
4. Re-run `php artisan scribe:generate` after touching any annotation.
5. Update `API_PROGRESS.md` in the same change as the code. A progress file that lags the
   code is worse than no progress file.
