# Introduction

REST API for the Electrotech ERP platform: sales pipeline, technical office, procurement, warehouse, manufacturing, delivery and accounting.

<aside>
    <strong>Base URL</strong>: <code>https://app.electrotech.findosystem.com</code>
</aside>

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

