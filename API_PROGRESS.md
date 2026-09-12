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
| **Last updated** | 2026-09-12 |
| **Modules complete** | **11 of 11 — the API surface is complete** |
| **Current module** | ✅ Modules 1-11 (DONE) |
| **Next module** | — none. See § What is deliberately not built |
| **Endpoints** | 229 routes under `/api/v1` |
| **Tests** | 404 API tests passing — 96 from Module 1, 168 from Modules 2-6, 140 from Modules 7-11 |
| **Docs** | Generated, committed at `public/api/docs/` |
| **Committed?** | Module 1 deployed 2026-08-08. **Modules 2-11 are BUILT AND UNCOMMITTED** — see § Uncommitted work |

### Module board

| # | Module | Status | Endpoints | Tests |
|---|--------|--------|-----------|-------|
| 1 | **Foundation & Identity** | ✅ **Done** | 22 | 96 |
| 2 | **Master Data & Files** — Items, Customers, Suppliers, Attachments | ✅ **Done** | 18 | 44 |
| 3 | **Sales & CRM** — Projects, Offers, BOQ | ✅ **Done** | 18 | 43 |
| 4 | **Technical Office / PMO** — BOMs, Standard BOM | ✅ **Done** | 8 | 19 |
| 5 | **Procurement** — Purchase Orders, Reservations | ✅ **Done** | 13 | 32 |
| 6 | **Inventory & Warehouse** — levels, transactions, addition/depreciation vouchers | ✅ **Done** | 15 | 30 |
| 7 | **Manufacturing & Material Movement** — Work Orders, Quality Sheets, issue/return vouchers, production entries | ✅ **Done** | 38 | 47 |
| 8 | **Delivery & Field Ops** — delivery vouchers/minutes, installations, surveys | ✅ **Done** | 27 | 23 |
| 9 | **Finance & Accounting** — GL, journals, invoices, payments, claims, facilities, cost-centre closing | ✅ **Done** | 43 | 37 |
| 10 | **Reports & Documents** — trial balance, ledger, daybook, statements, PDFs | ✅ **Done** | 16 | 14 |
| 11 | **Cross-cutting** — dashboard, notifications, activity log, search | ✅ **Done** | 8 | 19 |

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

## Modules 2-6 — the business core ✅

Five modules built in one round. They are described together because they share
one shape: a catalogue, a document with lines, a state machine, and a service
that already existed.

### Endpoints shipped (72)

**Module 2 — Master Data & Files** (`ability:master-data`)

| Method | Path | Permission |
|---|---|---|
| GET | `/api/v1/items` | `items.view` |
| GET | `/api/v1/items/{item}` | `items.view` |
| POST | `/api/v1/items` | `items.create` |
| PATCH | `/api/v1/items/{item}` | `items.edit` |
| DELETE | `/api/v1/items/{item}` | `items.delete` |
| GET | `/api/v1/customers` (+ show/store/update/destroy) | `customers.*` |
| GET | `/api/v1/suppliers` (+ show/store/update/destroy) | `suppliers.*` |
| GET | `/api/v1/attachments?owner_type=&owner_id=` | the owner's `view` policy |
| POST | `/api/v1/attachments` (multipart) | the owner's `update` policy |
| GET | `/api/v1/attachments/{attachment}/download` | the owner's `view` policy |
| DELETE | `/api/v1/attachments/{attachment}` | the owner's `update` policy |

**Module 3 — Sales & CRM** (`ability:sales`)

| Method | Path | Permission |
|---|---|---|
| GET/POST/PATCH/DELETE | `/api/v1/projects[/{project}]` | `projects.*` |
| POST | `/api/v1/projects/{project}/move-to-tender` | `projects.move_to_tender` |
| POST | `/api/v1/projects/{project}/move-to-in-hand` | `projects.move_to_inhand` |
| POST | `/api/v1/projects/{project}/move-to-active` | `projects.move_to_active` |
| POST | `/api/v1/projects/{project}/manager-approve` | `projects.manager_approve` |
| POST | `/api/v1/projects/{project}/cancel-to-lost` | `projects.cancel_to_lost` |
| PUT/DELETE | `/api/v1/projects/{project}/alarm` | `projects.set_alarm` |
| GET/POST | `/api/v1/projects/{project}/offers` | `project_offers.view/create` |
| GET/PATCH/DELETE | `/api/v1/offers/{offer}` | `project_offers.*` |
| PUT | `/api/v1/offers/{offer}/boq` | `project_offers.edit` |

**Module 4 — Technical Office / PMO** (`ability:technical-office`)

| Method | Path | Permission |
|---|---|---|
| GET/POST/PATCH/DELETE | `/api/v1/boms[/{bom}]` | `boms.*` |
| PUT | `/api/v1/boms/{bom}/items` | `boms.edit` |
| POST | `/api/v1/boms/{bom}/submit` | `boms.edit` |
| POST | `/api/v1/boms/{bom}/approve` | `boms.approve` |
| GET | `/api/v1/items/{item}/standard-bom` | `boms.view` |

**Module 5 — Procurement** (`ability:procurement`)

| Method | Path | Permission |
|---|---|---|
| GET/POST/PATCH/DELETE | `/api/v1/purchase-orders[/{purchase_order}]` | `purchase_orders.*` |
| PUT | `/api/v1/purchase-orders/{purchase_order}/items` | `purchase_orders.edit` |
| POST | `/api/v1/purchase-orders/{purchase_order}/approve` | `purchase_orders.approve` |
| POST | `/api/v1/purchase-orders/{purchase_order}/receive` | `purchase_orders.receive` |
| GET/POST | `/api/v1/stock-reservations[/{id}]` | `operations.reserve` |
| POST | `/api/v1/stock-reservations/{id}/release` | `operations.reserve` |
| POST | `/api/v1/projects/{project}/reserve-approved-bom` | `operations.reserve` |

**Module 6 — Inventory & Warehouse** (`ability:inventory`)

| Method | Path | Permission |
|---|---|---|
| GET | `/api/v1/inventory` | `transactions.view` |
| GET | `/api/v1/inventory-transactions` | `transactions.view` |
| GET | `/api/v1/items/{item}/stock-card` *(reports limiter)* | `transactions.view` |
| GET/POST/DELETE | `/api/v1/addition-vouchers[/{id}]` | `addition_vouchers.*` |
| POST | `/api/v1/addition-vouchers/{id}/post` | `addition_vouchers.post` |
| POST | `/api/v1/addition-vouchers/{id}/invoice` | `addition_vouchers.invoice` |
| POST | `/api/v1/addition-vouchers/{id}/close` | `addition_vouchers.invoice` |
| GET/POST/PATCH/DELETE | `/api/v1/depreciation-vouchers[/{id}]` | `depreciation_vouchers.*` |
| POST | `/api/v1/depreciation-vouchers/{id}/post` | `depreciation_vouchers.post` |

### Design decisions worth knowing before Module 7

**1. Line collections are REPLACED, never merged.**
The BOQ, BOM lines and purchase-order lines are each written with a single
`PUT` that carries the whole list. A phone on a weak link cannot reliably
sequence "add line 4, delete line 2, edit line 3": one dropped request in the
middle leaves the server holding a document that never existed on either side.
Sending the finished table is one decision the client can retry safely, and the
`Idempotency-Key` makes the retry free. **Module 7's work-order materials
should follow this.**

**2. Money is always derived, never posted.**
No endpoint accepts a total. The client sends quantities and unit prices; the
server multiplies, sums, applies VAT / installation / the profit-tax
withholding, and returns every component. Accepting a `grand_total` would let
the printed offer, the pipeline list and the ledger each hold a different
number for the same document.

Note the purchase-order total is `subtotal + VAT − profit tax` — the 1%
withholding is **deducted**, and skipped for an exempt supplier. Every
component is published rather than left to be re-derived, because a client
summing them naively would show the supplier ~2% too much.

**3. State transitions are POSTs with their own permissions, never a `status`
field on PATCH.** `projects.move_to_active` is not `projects.edit`;
`boms.approve` is not `boms.edit`; `addition_vouchers.post` is not
`addition_vouchers.create`. A state machine expressed as an editable column is
a state machine that gets bypassed. `UpdateProjectRequest` has no `status` rule
at all, and there is a test asserting a PATCH cannot move a stage.

**4. Receiving goods returns an ADDITION VOUCHER, not the order.**
`POST /purchase-orders/{id}/receive` raises and posts an إذن إضافة. That single
document adds the stock, credits the supplier, and closes the order by
comparing ordered against received. The voucher's number is what the warehouse
writes on the paperwork, so it is what comes back.

**5. Attachments name their owner with a key, never a class name.**
`owner_type` is one of `project`, `customer`, `supplier`, `purchase_order`,
`addition_voucher`, mapped to classes by `App\Http\Api\AttachmentOwner`.
`attachable_type` is a plain string column: if the request body could set it, a
caller could attach a row to any model in the app and read it back through an
endpoint whose authorization was written with suppliers in mind.

There are **no `attachments.*` permissions**. Attaching a file to a supplier is
an edit of that supplier and reading one is a view of it, so the owner's own
policy answers both. A separate permission would start out matching the panel
and drift the first time somebody changed one and not the other.

**6. Inventory balances and the ledger are read-only.**
Stock moves because a document was posted, never because a balance was written.
A writable balance could put the ledger and the on-hand figure out of step with
nothing to reconcile them against.

### Definition-of-done checklist (Modules 2-6)

**Contract**
- [x] Routes registered in `routes/api/v1.php`, one commented section per module
- [x] One `JsonResource` per entity; no model serialized directly
- [x] One `FormRequest` per write endpoint; no `$request->all()` anywhere
- [x] Filters / sorts / includes explicitly whitelisted via `ApiQuery`
- [x] Enum fields emitted as `{value,label,color}`
- [x] Money/decimal fields as strings — via the new `SerializesDecimals` trait

**Behaviour**
- [x] Writes delegate to a service; no business rules in controllers
- [x] `DomainException` → `422 business_rule_violated` — **and now service
      `RuntimeException` too, see Finding #14**
- [x] Every endpoint `authorize()`s against the existing policy
- [x] Detail GETs emit an `ETag` (via the existing `ConditionalGet` middleware)

**Performance**
- [x] No N+1 — the projects index has a query-count test that compares 3 rows
      against 9 rather than asserting a magic ceiling; it caught a real one
      (Finding #15)
- [x] Index endpoints paginated, `per_page` cap enforced
- [x] `stock-card` on the `api-reports` limiter — it walks a whole item history

**Security**
- [x] `401` swept across all routes by `RouteConventionsTest`
- [x] `403` per permission-gated endpoint — one test per module
- [x] Token-ability gate per module (`master-data`, `sales`, `technical-office`,
      `procurement`, `inventory`), each with a test
- [x] Uploads: extension allow-list, 20 MB cap, **generated** stored filename,
      downloads streamed through a policy-gated controller

**Tests** — 168 new tests across five directories
- [x] Happy path per endpoint
- [x] Validation failure per write endpoint
- [x] RBAC denial per gated endpoint
- [x] Unauthenticated denial
- [x] Business-rule violation for each state-machine guard
- [x] Idempotency replay does not double-write (items, offers, PO receipt,
      voucher post)
- [x] Pagination / filter / sort shape

**Docs**
- [x] Scribe annotations on every endpoint
- [x] Arabic terms named where the domain word is what people actually say
- [x] `php artisan scribe:generate` re-run and output committed
- [x] This file updated

---

## Changes to shared code (panel + API)

Design rule #1 says a missing rule goes in the **service**, so both the panel
and the API get it. Three rules were living inside Filament actions, where they
existed only if somebody clicked a button. They were moved:

| What | From | To |
|---|---|---|
| BOM approval | `BomResource`'s approve action | **new** `App\Services\BomService` |
| PO approval | `PurchaseOrderResource`'s approve action | `PurchaseOrderService::approve()` |
| PO editability + line replacement | nowhere (implicit) | `PurchaseOrderService::assertEditable()` / `replaceItems()` |

Both Filament actions now call the service and surface its message. This
tightened the panel slightly, deliberately:

- Approving a BOM now **supersedes** the previously approved version of the
  same subject, and refuses a BOM with no lines. Two approved recipes for one
  product made which one a work order used depend on an ordering tiebreak
  rather than on a decision anyone made.
- Approving a PO now refuses one with no lines (a receipt could never complete
  it, so it would sit in Submitted forever) or no supplier at all. This broke
  `PurchaseOrderApprovalTest`, whose fixture approved an empty order; the
  fixture gained the line item the test always implied it had.

  The supplier check accepts **either** `supplier_id` or the free-text
  `supplier_name`. Requiring the foreign key was the first attempt and was
  wrong: `PurchaseOrderFactory` and a real class of existing orders carry only
  the name (the supplier file came later than purchase orders), so those orders
  would have become unapprovable with no way to fix them short of editing the
  database.

---

## Modules 7-11 — the rest of the platform ✅

Five modules built in one round, finishing the API surface. Where Modules 2-6
were catalogues and documents, these are **state machines and money**, and
almost every design note below is about a gate.

### Endpoints shipped (132)

**Module 7 — Manufacturing & Material Movement** (`ability:manufacturing`)

| Method | Path | Permission |
|---|---|---|
| GET/POST/PATCH/DELETE | `/api/v1/work-orders[/{work_order}]` | `work_orders.*` |
| PUT | `/api/v1/work-orders/{id}/outputs` | `work_orders.edit` |
| PUT | `/api/v1/work-orders/{id}/materials` | `work_orders.edit` |
| POST | `/api/v1/work-orders/{id}/fetch-standard-materials` | `work_orders.edit` |
| GET | `/api/v1/work-orders/{id}/material-requirement` *(reports limiter)* | `work_orders.view` |
| GET | `/api/v1/work-orders/{id}/material-variance` *(reports limiter)* | `work_orders.view` |
| POST | `/api/v1/work-orders/{id}/approve-order` | `work_orders.approve_order` |
| POST | `/api/v1/work-orders/{id}/start` | `work_orders.start` |
| POST | `/api/v1/work-orders/{id}/submit-qa` | `work_orders.submit_qa` |
| POST | `/api/v1/work-orders/{id}/approve-qa` | `work_orders.approve_qa` |
| POST | `/api/v1/work-orders/{id}/finish-manufacturing` | `work_orders.finish_manufacturing` |
| POST | `/api/v1/work-orders/{id}/complete` | `work_orders.complete` |
| POST | `/api/v1/work-orders/{id}/quality-sheet` | `quality_sheets.create` |
| GET/POST/DELETE | `/api/v1/issue-vouchers[/{id}]` | `issue_vouchers.*` |
| PUT | `/api/v1/issue-vouchers/{id}/lines` | `issue_vouchers.create` |
| POST | `/api/v1/issue-vouchers/{id}/post` | `issue_vouchers.post` (+ `approve_excess`) |
| GET | `/api/v1/issue-vouchers/{id}/excess` | `issue_vouchers.view` |
| GET/POST/DELETE | `/api/v1/return-vouchers[/{id}]` | `return_vouchers.*` |
| PUT | `/api/v1/return-vouchers/{id}/lines` | `return_vouchers.create` |
| POST | `/api/v1/return-vouchers/{id}/post` | `return_vouchers.post` |
| GET/DELETE | `/api/v1/quality-sheets[/{id}]` | `quality_sheets.*` |
| PUT | `/api/v1/quality-sheets/{id}/lines` | `quality_sheets.create` |
| POST | `/api/v1/quality-sheets/{id}/fill` | `quality_sheets.fill` |
| POST | `/api/v1/quality-sheets/{id}/approve` | `quality_sheets.approve` |
| GET | `/api/v1/production-entries[/{id}]` | `production_entries.view` |

**Module 8 — Delivery & Field Ops** (`ability:delivery`)

| Method | Path | Permission |
|---|---|---|
| GET/POST/PATCH/DELETE | `/api/v1/delivery-vouchers[/{id}]` | `delivery_vouchers.*` |
| PUT | `/api/v1/delivery-vouchers/{id}/lines` | `delivery_vouchers.create` |
| POST | `/api/v1/delivery-vouchers/{id}/approve-technical` | `delivery_vouchers.approve_technical` |
| POST | `/api/v1/delivery-vouchers/{id}/approve-financial` | `delivery_vouchers.approve_financial` |
| POST | `/api/v1/delivery-vouchers/{id}/cancel` | `delivery_vouchers.cancel` |
| GET/POST/PATCH/DELETE | `/api/v1/delivery-minutes[/{id}]` | `delivery_minutes.*` |
| POST | `/api/v1/delivery-minutes/{id}/distribute` | `delivery_minutes.distribute` |
| GET/POST/PATCH/DELETE | `/api/v1/installations[/{id}]` | `installations.*` |
| POST | `/api/v1/installations/{id}/start` \| `/complete` | `installations.manage` |
| GET/POST/PATCH/DELETE | `/api/v1/site-surveys[/{id}]` | `site_surveys.*` |

**Module 9 — Finance & Accounting** (`ability:finance`)

| Method | Path | Permission |
|---|---|---|
| GET/POST/PATCH/DELETE | `/api/v1/accounts[/{account}]` | `accounts.*` |
| GET/POST/PATCH/DELETE | `/api/v1/journal-entries[/{id}]` | `journal_entries.*` |
| PUT | `/api/v1/journal-entries/{id}/lines` | `journal_entries.edit` |
| POST | `/api/v1/journal-entries/{id}/post` | `journal_entries.post` |
| GET | `/api/v1/sales-invoices[/{id}]` | `sales_invoices.view` |
| POST | `/api/v1/delivery-vouchers/{id}/invoices` | `sales_invoices.create` |
| DELETE | `/api/v1/sales-invoices/{id}` | `sales_invoices.delete` |
| GET/POST/DELETE | `/api/v1/operation-payments[/{id}]` | `operation_payments.*` |
| POST | `/api/v1/operation-payments/{id}/allocate/{claim}` | `operation_payments.record` |
| GET | `/api/v1/projects/{project}/payment-totals` | `operation_payments.view` |
| GET/POST/PATCH/DELETE | `/api/v1/financial-claims[/{id}]` | `financial_claims.*` |
| POST | `/api/v1/financial-claims/{id}/submit` \| `/collect` | `financial_claims.submit` / `.collect` |
| GET/POST/PATCH/DELETE | `/api/v1/credit-facilities[/{id}]` | `credit_facilities.*` |
| POST | `/api/v1/credit-facilities/{id}/allocate` | `credit_facilities.manage` |
| POST | `/api/v1/facility-allocations/{id}/release` | `credit_facilities.manage` |
| GET | `/api/v1/account-entries` | `customer_statements.view` \| `supplier_statements.view` |
| GET | `/api/v1/customers/{id}/statement` *(reports limiter)* | `customer_statements.view` |
| GET | `/api/v1/suppliers/{id}/statement` *(reports limiter)* | `supplier_statements.view` |
| GET | `/api/v1/cost-center-closings`, `/projects/{id}/cost-center` | `operations.view_cost` |
| POST | `/api/v1/projects/{id}/close-cost-center` | `operations.close_cost_center` |
| POST | `/api/v1/cost-center-closings/{id}/reverse` | `operations.close_cost_center` |

**Module 10 — Reports & Documents** (`ability:reports`, all on `throttle:api-reports`)

| Method | Path | Permission |
|---|---|---|
| GET | `/api/v1/reports/trial-balance` | `trial_balance.view` |
| GET | `/api/v1/reports/general-ledger` | `general_ledger.view` |
| GET | `/api/v1/reports/journal-daybook` | `journal_daybook.view` |
| GET | `/api/v1/reports/income-statement` | `income_statement.view` |
| GET | `/api/v1/reports/balance-sheet` | `balance_sheet.view` |
| GET | `/api/v1/reports/cash-flow` | `cash_flow_statement.view` |
| GET | `/api/v1/reports/operating-statement` | `operating_statement.view` |
| GET | `/api/v1/projects/{id}/cost-breakdown` | `operations.view_cost` |
| GET | `/api/v1/projects/{id}/timeline` | `operations.overview` |
| GET | `/api/v1/documents/...` (7 PDF routes) | each document's own `*.print` / report permission |

**Module 11 — Cross-cutting** (no token ability — see below)

| Method | Path | Permission |
|---|---|---|
| GET | `/api/v1/dashboard` *(reports limiter)* | `dashboard.view` |
| GET | `/api/v1/notifications`, `/unread-count` | **none — self-scoped** |
| POST | `/api/v1/notifications/{id}/read`, `/read-all` | **none — self-scoped** |
| DELETE | `/api/v1/notifications/{id}` | **none — self-scoped** |
| GET | `/api/v1/activity-log` | `activity_log.view` |
| GET | `/api/v1/search` | each type's own `*.view` |

### Design decisions worth knowing

**1. The excess-issue gate gets its own `error.code`.**
`ExcessIssueException` is the only refusal in the platform the *client* can
resolve: a user holding `issue_vouchers.approve_excess` may retry the same post
with `allow_excess` and a written reason. So it renders as
`422 issue_excess_requires_approval` with the offending rows in
`details.excess`, not as the generic `business_rule_violated`. A client cannot
offer the approve-excess flow if it cannot tell that refusal apart from "this
voucher is already posted", and it cannot pre-fill the confirmation screen
without the rows.

`GET /issue-vouchers/{id}/excess` runs the same comparison without posting, so
a client can warn before the user commits rather than after they are refused.

**2. Approving an overage is checked in the CONTROLLER, not the service.**
`IssueVoucherService::post()` takes `$allowExcess` as a parameter and trusts
it, because the panel gates the same decision by hiding the action. The API has
no UI to hide, so `issue_vouchers.approve_excess` is authorized in the
controller before the flag is passed down. Putting it in the service would have
been the wrong place *and* a change to shared code the panel already gates
correctly.

**3. Dual approval is two endpoints, never one.**
A delivery voucher needs a technical signature and a financial one, in either
order, and the second one activates it — deducting finished goods, debiting the
customer, and closing the operation's cost centre. They are two routes with two
permissions because one person holding a merged `approve` would defeat the
point of having two signatures.

The signature and the activation are **one transaction**. If activation fails
(insufficient finished goods is the common one) the signature must not survive
it, or the voucher would show an approval that never took effect while the user
was told it failed. `DeliveryApiTest::test_a_failed_activation_does_not_leave_a_signature_behind`
locks that in.

**4. A posted document is immutable, and the API has no route that pretends
otherwise.** There is no edit or delete for a posted journal entry, no way to
un-post one, and no `status` on any PATCH anywhere in these five modules.
Cost-centre closings are **reversed** rather than deleted — a mirror-image entry
plus a negative closing row — so the unclosed balance returns on its own and
the audit trail stays whole.

**5. Party statements and production entries are read-only surfaces.**
Neither has a write route, and both policies refuse create/update/delete.
`AccountEntry` rows are written by documents (posting a receipt credits its
supplier; activating a delivery debits its customer), and production entries
are written by a work order completing. An editable statement could be brought
into line with a balance somebody expected rather than with the documents that
produced it.

**6. `AccountEntry.amount` carries the SIGN; `direction` is the label.**
This surprised us, so it is now documented in the resource. A party's balance
is the plain `SUM(amount)` — that is what `Customer::getBalanceAttribute`, its
supplier twin and the panel's own running-balance column all compute. A client
that re-signs the figure by reading `direction` would make every settlement add
instead of subtract.

**7. Module 11 is deliberately NOT behind a token ability.**
Every other module has a natural scope a device token can be narrowed to; the
dashboard, notifications, the activity log and search span all of them.
Inventing a `platform` ability would have locked **every existing token** out of
the bell and the search box on the first deploy — tokens carry the abilities
they were issued with, and there is no way to widen one already in the field.
The same reasoning as the "no `api.access` permission" decision in Module 1.

Authorization is per endpoint instead, and notifications carry **no permission
at all**: they are self-scoped, which is the strongest rule available. There is
no endpoint that reads another user's notifications, so there is nothing to get
wrong. Another user's notification id answers **404**, not 403 — a 403 would
confirm that it exists.

**8. Global search respects each type's own view permission.**
A user who cannot view suppliers gets no supplier results, and `data.types`
reports what was *actually* searched so a client can say so rather than
presenting a permission gap as an absence. A global search that ignored
per-type permissions would be the easiest way in the platform to confirm a
record exists without being allowed to read it.

**9. PDFs delegate to the panel's own print controllers.**
`/documents/*` streams `application/pdf` — the one place the response is not
the JSON envelope — and calls the same controllers the admin panel prints
through. Re-implementing the layouts for the API would guarantee the two drift,
and the printed offer a customer holds is not a thing to let drift. Each keeps
its own `*.print` permission: being able to read a record is not being able to
print it.

**10. Report shapes come from the services, normalized by type.**
`App\Http\Api\ReportSerializer` walks a statement's structure and normalizes by
type rather than by shape: `Account` → `{id, code, name, type}`, `Carbon` →
date string, enum → `{value,label,color}`, **float → decimal string**, integers
left alone. Nine hand-written resources would each need revising whenever a
statement gains a line, and the first one missed would silently leak a whole
Eloquent model.

### Definition-of-done checklist (Modules 7-11)

**Contract**
- [x] Routes registered in `routes/api/v1.php`, one commented section per module
- [x] One `JsonResource` per entity; no model serialized directly
      (reports go through `ReportSerializer` — see decision #10)
- [x] One `FormRequest` per write endpoint; no `$request->all()` anywhere
- [x] Filters / sorts / includes explicitly whitelisted via `ApiQuery`
- [x] Enum fields emitted as `{value,label,color}`
- [x] Money/decimal fields as strings, at each column's real precision

**Behaviour**
- [x] Writes delegate to the existing service; no business rules in controllers
      (the two exceptions are documented: the excess permission check, and the
      delivery-minute operation guard)
- [x] `DomainException` and service `RuntimeException` → `422 business_rule_violated`
- [x] `ExcessIssueException` → `422 issue_excess_requires_approval` with rows
- [x] Every endpoint `authorize()`s against the existing policy or permission
- [x] Detail GETs emit an `ETag`

**Performance**
- [x] No N+1 — query-count tests on the work-order, delivery-voucher and
      journal-entry indexes, each comparing 3 rows against 9
- [x] Three N+1s found and fixed with subquery aggregates (Finding #21)
- [x] Index endpoints paginated, `per_page` cap enforced
- [x] All of Module 10, plus the dashboard, the material requirement/variance
      and the two party statements, on the `api-reports` limiter

**Security**
- [x] `401` swept across all 229 routes by `RouteConventionsTest`
- [x] `403` per permission-gated endpoint — at least one test per module
- [x] Token-ability gate per module (`manufacturing`, `delivery`, `finance`,
      `reports`), each with a test; Module 11 deliberately exempt (decision #7)
- [x] Self-scoped endpoints answer 404, not 403, for another user's record
- [x] Search cannot confirm the existence of a record the caller may not view

**Tests** — 140 new tests across five directories
- [x] Happy path per endpoint
- [x] Validation failure per write endpoint
- [x] RBAC denial per gated endpoint
- [x] Unauthenticated denial
- [x] Business-rule violation for each state-machine guard
- [x] Idempotency replay does not double-write (work order create, issue
      voucher post, delivery approval, journal post)
- [x] Pagination / filter / sort shape

**Docs**
- [x] Scribe annotations on every endpoint, groups 20-42
- [x] Arabic terms named where the domain word is what people actually say
- [x] `php artisan scribe:generate` re-run and output committed
- [x] This file updated

---

## Findings — Modules 7-11

Continuing the numbering from Modules 2-6.

### 21. Three more N+1s, all hiding behind an accessor in a resource

The same shape as Finding #15 and worth stating as a rule, because it will
recur: **a model accessor that sums a relation is an N+1 the moment a resource
reads it on a list page.**

| Where | Accessor | Fix |
|---|---|---|
| `WorkOrderResource` | `planned_material_cost` sums `materials` | `withSum('materials as materials_plan_value', DB::raw('quantity * unit_cost'))` |
| `DeliveryVoucherResource` | `lines_value` sums `lines` | `withSum('lines as lines_value_sum', ...)` |
| `JournalEntryResource` | `linesDebitTotal()` / `linesCreditTotal()` | two filtered `withSum`s, one per direction |

One wrinkle cost a debugging pass and is now the rule for all three: **check
for the presence of the ATTRIBUTE, not for a non-null value.** A work order
with no material lines sums to SQL `NULL`, so

```php
$this->materials_plan_value ?? $this->planned_material_cost   // WRONG
```

falls through to the accessor on exactly those rows, lazy-loads the relation
and reintroduces the N+1 — while *looking* correct, because a row with lines
takes the fast path. The right test is `array_key_exists(..., $this->resource->getAttributes())`.

### 22. `WorkOrder::generateWoNumber()` was the last MySQL-only generator

Exactly Finding #16 again, in the one place that was missed: `SUBSTRING_INDEX`
inside a `selectRaw`, which works in production and throws on SQLite, so **the
whole work-order create path was untestable**. Now parses the sequence in PHP
like `Project::generateCode()` and the rest, and includes soft-deleted rows —
the unique index on `wo_number` ignores `deleted_at`, so reusing a deleted
number would fail on insert.

Grepping `SUBSTRING_INDEX` across `app/` now returns nothing.

### 23. The report services type-hint Laravel's Carbon, not the base one

`IncomeStatementService::build(?Illuminate\Support\Carbon $from)`. Passing a
`Carbon\Carbon` — which is what `Carbon::parse()` returns if you import the
base class — is a `TypeError`, rendered as a 500. Import
`Illuminate\Support\Carbon` in anything that feeds a report service.

### 24. A delivery minute cannot exist without an operation

`delivery_minutes.project_id` is NOT NULL, and a delivery voucher's
`project_id` is nullable. Raising a minute from a voucher with no operation was
therefore a database error surfacing as a 500. It is now an explicit
`422 business_rule_violated` naming the voucher — a minute is filed in an
operation's folder and circulated in its name, so there is genuinely nowhere to
put one without an operation.

### 25. `WorkOrder.project_id` is NOT NULL, so `project_id` is required

The first draft of `StoreWorkOrderRequest` had it nullable, which produced a
500 on the insert. It is required now, and that is the honest contract: the
operation is the cost centre every issued material is loaded onto, so an order
without one would have nowhere to put its cost. (Contrast purchase orders,
where an empty project deliberately means a warehouse order.)

### 26. `ProjectFactory` randomizes status, which makes count assertions flaky

`WorkOrder::factory()` creates a project per order, and `ProjectFactory` picks
a random `ProjectStatus`. A dashboard test that created three work orders and
asserted "2 active operations" passed alone and failed in the full suite,
because some of those incidental projects rolled `InProgress`.

**Any test that asserts a count must pin the status of every record it creates
incidentally**, not just the ones it creates on purpose.

### 27. `CreditFacilityService::utilization()` returns `used`, not `allocated`

A small thing that would have shipped a wrong field name in the contract. The
resource publishes `{limit, used, available, percent}`, and `percent` is
**null** when the limit is zero — a percentage of nothing is undefined, not
zero, and flattening the two would draw a full gauge on an empty facility.

---

## Findings — Modules 2-6

Continuing the numbering from Module 1.

### 14. Fifty business rules would have surfaced as `500 server_error`

The codebase signals "your payload was fine, the business state was not" with
**two** exception types. Twelve places throw `DomainException`; **fifty** throw
`\RuntimeException` carrying a localized `__('errors...')` message — and the
Filament panel catches `\RuntimeException` and shows that message to the user
as a notification. So in practice a service's `RuntimeException` *is* the
business-rule signal, whatever the type name suggests.

`ApiExceptionRenderer` only knew about `DomainException`. Every one of those
fifty rules would have reached a client as an unhandled `500 server_error` with
a generic message, while the panel showed the real one. "Insufficient stock for
Copper Busbar in Raw Materials. Available: 12, Requested: 40" would have
arrived at a warehouse tablet as "An unexpected error occurred" — the caller
could not tell a refusal from an outage, and would retry a request that can
never succeed.

Fixed with `isServiceRuntimeException()`: a `RuntimeException` whose own throw
frame is inside `app/Services` is rendered as `422 business_rule_violated`.
The origin check is what keeps it narrow — a `RuntimeException` from a database
driver, a filesystem call or a vendor package is a genuine fault and stays a
logged 500, because surfacing its message as user-facing prose would leak
internals and tell the client to fix something it cannot.

**The proper fix is a single explicit exception type across all sixty throw
sites.** That is a change to shared services the panel depends on, so it was
deliberately not bundled with this round. Do it before the module count makes
it a bigger change than it is now.

### 15. `has_smb` was an N+1 hiding in a resource

`ProjectResource` called `$this->hasSmb()`, which runs an `EXISTS` query. On a
25-row page that is 25 extra queries for a boolean. The index now adds a
`withExists` aggregate resolved as one subquery, and the resource reads that
when present, falling back to the method for single-record endpoints.

Worth noting *how* it was caught. The first version of the test asserted a
fixed ceiling (`assertLessThan(14, $queries)`), which is a magic number that
drifts whenever the auth path or the permission cache changes. Rewritten to
measure the same endpoint over 3 rows and over 9 and assert the two agree,
which is what an N+1 actually *is*. **Copy that shape in Module 7.**

One wrinkle: the first request of a test also warms Spatie's permission cache,
so the measurement needs a throwaway call first — otherwise it reports *fewer*
queries for more rows.

### 16. `PurchaseOrder::generatePoNumber()` was MySQL-only

It used `SUBSTRING_INDEX`, which SQLite does not have. The query worked in
production and threw on the test database, so **the entire purchase-order
create path was untestable** and nobody had noticed.

`Project::generateCode()` and `AdditionVoucher::generateVoucherNumber()` both
parse the sequence in PHP with a comment explaining it is "portable across
MySQL (production) and SQLite (tests)". This one was missed. Now it matches
them, and also includes soft-deleted rows — the unique index on `po_number`
ignores `deleted_at`, so reusing a deleted number would fail on insert.

**Check any other `selectRaw` before relying on it in a test.**

### 17. Uploads must go through a multipart helper, not `apiJson`

`ApiTestCase::apiJson()` serializes the payload as a JSON body, so an
`UploadedFile` arrives at the server as an object literal and validation
rejects it as "not a file" — which looks exactly like a broken rule rather than
a broken test. Added `apiUpload()`, which uses Laravel's `post()` helper and
sets `Content-Type: multipart/form-data`.

That header matters twice over: Laravel's `post()` defaults to
form-urlencoded, which `ForceJsonResponse` answers with a **415**, so without
it every upload test fails for a reason unrelated to the endpoint. A real
Flutter client sends `multipart/form-data` with a boundary, which the
middleware allows.

### 18. An idempotency test must reuse one token

`actingAsApi()` issues a **new** token each call. Since Finding #3 keys the
idempotency cache on the bearer token, calling it twice puts the two requests
in different scopes — so the replay legitimately executes again and the test
asserts nothing. Call `actingAsApi()` once, then make both requests.

### 19. A new offer's money columns were `null`, not zero

The derived columns are filled by `OfferTotalsService`, which had not run yet
on a freshly created offer. The API promises money fields are decimal strings;
a client having to handle `null` on a brand-new offer and `"0.00"` a second
later will get one of the two branches wrong. `store()` now runs the totals
service once so the offer starts at zero.

### 20. Policy checks can fire before a service's own guard

Posting an already-posted addition voucher returns **403**, not 422, because
`AdditionVoucherPolicy::post()` gates on `! $voucher->isPosted()`. That is the
same order the panel applies (the action is hidden once posted) and the
service check behind it remains as the last line of defence — but a client
branching on `error.code` needs to expect `forbidden` there, not
`business_rule_violated`. The same is true of editing a posted voucher.

---

## Uncommitted work

**Modules 2-11 are built, tested and NOT committed.** `main` in production
still serves Module 1 only.

Before shipping this round:

1. `php artisan test tests/Feature/Api` — expect 404 passing.
2. `php artisan scribe:generate`, then commit `public/api/docs/` **and**
   `.scribe/` (both are build product that production cannot rebuild, because
   Scribe is `require-dev` and the server installs `--no-dev`).
3. Confirm `config:cache` succeeds locally — Finding #12 is still the failure
   mode that takes the whole site down.
4. No new migration and no new permission in this round either, so `deploy.sh`
   needs no change. Every endpoint in Modules 7-11 reuses a permission the
   seeder already grants.

### Changes to shared code in this round

Two, both narrow:

| What | Why |
|---|---|
| `WorkOrder::generateWoNumber()` now parses its sequence in PHP | Finding #22 — it was MySQL-only, so the create path was untestable. Behaviour in production is identical. |
| `ApiExceptionRenderer` gained an `ExcessIssueException` branch | Design decision #1. Placed **before** the generic `RuntimeException` branch, since `ExcessIssueException` is one. |

Thirteen new `errors.api.*` strings were added in both locales for the guards
these modules raise from controllers.

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

`php artisan test tests/Feature/Api` — **404 tests**

| Suite | Tests | Covers |
|---|---|---|
| `Foundation/ApiContractTest` | 23 | Envelope shape, headers, request id, 404/405/415, idempotency (incl. cross-caller isolation), ETag/304, rate limits, locale negotiation, enum catalog, no stack-trace leak |
| `Foundation/ApiDocsTest` | 9 | Docs page reachable, assets referenced absolutely, generated files present, OG card, OpenAPI base URL |
| `Foundation/RouteConventionsTest` | 6 | Every route: authenticated, throttled, named, versioned, no closures; public-route exemption list kept honest |
| `Auth/AuthenticationTest` | 16 | Login, credential-enumeration resistance, roleless account, token expiry, logout/logout-all, rotation, ability narrowing, LRU eviction |
| `Auth/ProfileAndDeviceTest` | 13 | me/profile/password, privilege-escalation guard, device list/revoke, cross-user revoke |
| `Identity/UserManagementTest` | 17 | CRUD, pagination/filter/sort/search, N+1 guard, RBAC sweep, admin password reset revokes sessions |
| `Identity/RoleAndPermissionTest` | 12 | Role CRUD, permission catalog, cache invalidation, Admin-role protection, i18n labels |
| `MasterData/ItemApiTest` | 18 | CRUD, enum + decimal-string shape, per-warehouse stock, below-minimum filter, N+1 guard, idempotent create, delete blocked while stocked |
| `MasterData/PartyApiTest` | 13 | Customers and suppliers side by side: CRUD, ledger balance on detail only, profit-tax-exemption filter, delete guards |
| `MasterData/AttachmentApiTest` | 13 | Upload/list/download/delete, owner-type whitelist, generated filename, MIME allow-list, owner-policy gating, missing-file 404 |
| `Sales/ProjectApiTest` | 16 | CRUD, server-generated code, status not settable via PATCH, stage + missing-offer filters, query-count invariance |
| `Sales/PipelineApiTest` | 14 | Every transition and its pre-conditions, SMB derivation, winning-offer marking, per-transition permissions, alarms |
| `Sales/OfferApiTest` | 13 | Offer versioning, BOQ replace, derived totals incl. VAT + installation, winning-offer lock, idempotent create |
| `TechnicalOffice/BomApiTest` | 19 | Project vs standard scope, line replacement, waste-adjusted quantity, draft→pending→approved, supersede rules, immutability, standard-recipe lookup |
| `Procurement/PurchaseOrderApiTest` | 20 | Money breakdown incl. deducted profit tax, approve guards, receive → addition voucher, over-receipt refusal, draft-only editing, idempotent receipt |
| `Procurement/StockReservationApiTest` | 12 | Hold/release, availability guard, double-promise guard, bulk approved-BOM reserve is all-or-nothing |
| `Inventory/InventoryApiTest` | 11 | Balances (on hand / on hold / available), ledger filters, stock card reshaped to the API contract, read-only surface |
| `Inventory/VoucherApiTest` | 19 | Addition voucher draft→post→invoice/close, derived invoicing status, invoice-value mismatch, depreciation pre-fill → post → WIP deduction + journal |
| `Manufacturing/WorkOrderApiTest` | 20 | Draft creation + generated number, status not settable via PATCH, material/output replacement, the full approve→start→submit-qa→approve-qa→complete chain, plan gates, the double-approval finish gate, query-count invariance, idempotent create |
| `Manufacturing/MaterialMovementApiTest` | 16 | Issue voucher pre-filled from the remaining requirement, posting moves raw→WIP and loads the operation, the excess gate and its approval flow, insufficient stock, return voucher pre-filled at zero and reversing value, idempotent post |
| `Manufacturing/QualitySheetApiTest` | 11 | Idempotent sheet opening, spec snapshot frozen against later order edits, test-grid round trip, fill→approve lifecycle, approved sheet frozen, production entries read-only |
| `Delivery/DeliveryApiTest` | 23 | Dual signature in either order, activation moving stock + debiting the customer, atomic rollback of a failed activation, active voucher frozen, minute inheritance and one-way distribution, installation stages, site surveys, query-count invariance |
| `Finance/LedgerApiTest` | 17 | Account CRUD + natural sign, delete guards, two-column entry writing, double-entry enforcement, posted-entry immutability, line update/keep/delete in one pass, query-count invariance, idempotent post |
| `Finance/ReceivablesApiTest` | 20 | Invoicing in instalments with derived status, over-invoicing refused, payment totals, claim submit/collect gates and auto-collection, facility double-promise guard and release, party statements with running balance |
| `Reports/ReportApiTest` | 14 | Trial balance grouped by currency, decimal-string money, ledger opening/closing/running balance, backwards period refused, drafts excluded, the four statements, operation cost + timeline, per-report permissions, PDF streaming |
| `Platform/PlatformApiTest` | 19 | Dashboard sharing the panel's cache keys, self-scoped notifications (404 not 403 across users), unread count, activity log read-only, search respecting per-type permissions, LIKE-wildcard escaping |

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

## What is deliberately not built

The eleven modules are done. What remains unbuilt is unbuilt on purpose, and
each of these is a decision rather than a gap:

| Not built | Why |
|---|---|
| **Push notifications** | The API exposes the notification *rows* (Module 11); delivering them to a device needs FCM credentials, a device-token registry and a production decision about what is worth waking a phone for. The read surface is what the app needs to show a bell; push is a separate project. |
| **A real delta-sync protocol** | `updated_after` is a plain `updated_at >` filter and the server holds no cursor. The sync layer was deleted on purpose (plan §1.3); re-adding one would re-create the class of bug that removal was meant to end. |
| **Password reset by email** | `MAIL_MAILER=log`, and the panel has no reset flow either. Administrators reset via `PATCH /users/{user}`. |
| **Write endpoints for production entries, party statements and the activity log** | All three are written by documents or by the system, and all three policies refuse writes. An editable audit surface is not an audit surface. |
| **A `v2`** | Additive changes are non-breaking by the contract in plan §3.1. A new version file is added when something genuinely breaks, never as a tidy-up. |

### If you are adding a twelfth thing

The conventions that survived eleven modules and are worth copying:

1. **The module sections in `routes/api/v1.php`** — one `ability:` group, split
   into `throttle:api-read` / `api-write` / `api-reports`, with a comment block
   saying what the module is and why anything unusual in it is that way.
2. **Replace, never merge**, for any line collection (`PUT .../lines`).
3. **A state change is a POST with its own permission**, never a `status` on
   PATCH.
4. **`SerializesDecimals` on every resource**, at the column's real precision —
   `decimal(*,2)` for money, `decimal(*,4)` for quantities.
5. **The query-count test shape**: 3 rows against 9, with a warm-up call first,
   and never a fixed ceiling.
6. **Check for the attribute, not for non-null**, when a resource prefers a
   subquery aggregate over an accessor (Finding #21).
7. **Read the policy before asserting a status code.** Several policies gate on
   state (`! isPosted()`, `isDraft()`, `! isActive()`), so the "already done"
   case answers **403**, not 422. Assert what the policy actually does.

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
| `GeneralManagement/DeliveryMinuteTest` (notification count) | Expects 1 notification, gets a different count | Confirmed pre-existing during the Modules 2-6 round by stashing every change and re-running on a clean tree — it fails there too. Not diagnosed further; it is unrelated to the API. |

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
