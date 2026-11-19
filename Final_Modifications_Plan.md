# Final Modifications Plan — "تعديلات اخيرة.pptx"

Source deck: `تعديلات اخيرة.pptx` (4 slides). Executed with subagent-driven development
(controller = Opus, all implementers/reviewers = Sonnet 5.5, effort per task).

## Global Constraints (binding on every task)

- G1. Work directly on `main`. No branches, no worktrees. Each task ends with ONE commit
  (Conventional Commits, English, ends with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`).
  Implementers do NOT push; the controller pushes.
- G2. NOTHING runs on the developer machine: no `php artisan`, no `phpunit`, no `composer`,
  no `npm`, no browser, no MySQL. Verification happens only in GitHub Actions (Task 1),
  which gates the production deploy. Implementers write code + tests carefully, then do a
  static self-review (read their diff, check imports/namespaces/translation keys/migrations).
- G3. Every behaviour change gets PHPUnit feature tests under `tests/Feature/` (sqlite
  in-memory, see `phpunit.xml`) and, where it is a UI flow, an E2E spec under `tests/e2e/`
  (Task 9 owns the harness; earlier tasks only add PHPUnit).
- G4. RBAC through spatie permissions in `database/seeders/RoleAndPermissionSeeder.php`.
  The seeder does NOT update existing roles on prod — any permission grant/revoke for
  existing roles must also ship as an idempotent migration (pattern: search
  `database/migrations` for earlier `grant`/`givePermissionTo` migrations).
- G5. i18n: every user-facing string in `lang/ar/*.php` AND `lang/en/*.php`.
- G6. UI conventions: Filament 3.3; row actions collapse into one ⋮ ActionGroup
  (tooltip `resources.common.actions`); custom blade partials live under
  `resources/views/filament/` with the `et-report` marker class and warm tokens
  (`dark:bg-[var(--surface-2)]`, never gray/slate).
- G7. REST API (`app/Http/Controllers/Api/V1`, Resources, FormRequests) is kept in sync with
  every model/field/permission change, with Scribe docblocks updated. Do not run scribe.
- G8. Accounting: posted journal entries are never deleted — corrections are reversing
  entries. Every auto-journal goes through `JournalEntryService` (see how
  `DepreciationVoucherService` and `CostCenterClosingService` build + post). Auto-posting must
  never throw out of the voucher flow when an account is missing — log + skip, the same way
  `CostCenterClosingService` auto path behaves. Every auto-journal is idempotent per source
  document (a source reference column / lookup prevents double posting).
- G9. Existing tests must stay green. Known pre-existing failures:
  `ActivityLogTest::subject_type_is_translated` and two in `NetworkResilienceTest` — Task 1
  fixes or explicitly skips them with a reason so CI can be a hard gate.

## Rulings (decided by controller, auto-approved per user instruction)

- R1. Inventory accounts: keep `1300 المخزون` as the parent (non-postable if the model
  supports it) and add `1310 مخزن الخامات`, `1320 مخزن تحت التشغيل`, `1330 مخزن المنتجات التامة`.
  Config keys in `config/operations.php`: `raw_materials_account_code`, `wip_account_code`,
  `finished_goods_account_code`; `inventory_account_code` stays as fallback for old code.
  Cost if wrong: renumber via seeder + migration.
- R2. No backfill of historic journals. Auto journals apply to documents activated after
  deploy. The existing 1300 balance is reclassified by Finance with one manual entry
  (documented in the report page help text). Cost if wrong: a backfill command later.
- R3. Restricted drawings (`PreliminaryDrawing`, `ExecutiveDrawing`, `SiteMeasurement`):
  visible only with new permission `attachments.view_restricted_drawings`, granted to
  `Timeline_Coordinator` (أسماء). Admin keeps it implicitly (admin gets all). General
  Manager does NOT get it. Cost if wrong: one grant migration.
- R4. Operation completion: the manual button stays but is gated — allowed only when
  (a) every manufacturing order of the operation is finished (factory-manager finish),
  (b) each has a passed quality sheet, (c) all ordered quantities are delivered via activated
  delivery vouchers. `operations.complete` revoked from every Sales* role on prod (migration).
  Cost if wrong: relax the guard.
- R5. Addition voucher credit side: supplier account (`2010` / supplier's own account if the
  model has one) unless the PO/voucher records a cash payment method, then the treasury
  account. Must not duplicate any entry already posted by `PurchaseInvoicingService` —
  if that service already debits inventory, the voucher journal replaces that leg.
- R6. Scribe API docs are not regenerated locally (25 min, forbidden by G2); docblocks are
  updated and regeneration is listed as a follow-up.

## Task 1: CI test gate (GitHub Actions)

Effort: medium. Files: `.github/workflows/tests.yml` (new), `.github/workflows/deploy.yml`.

- New workflow `tests.yml` on push to main + `workflow_call`: ubuntu-latest, PHP 8.2 with
  sqlite/pdo_sqlite/mbstring/intl/gd/zip extensions, composer install (cached),
  `cp .env.example .env && php artisan key:generate`, `php artisan test --parallel` if
  paratest is installed else `php artisan test`.
- Second job `e2e` (needs phpunit job): placeholder that Task 9 fills — for now only create
  the job skeleton guarded by `if: hashFiles('tests/e2e/package.json') != ''`.
- `deploy.yml`: deploy only after tests pass — convert its trigger to `workflow_run` on
  `tests` completed with `conclusion == 'success'` on main (keep `workflow_dispatch`).
  Skip the deploy when the head commit message contains `[no-deploy]`
  (`github.event.workflow_run.head_commit.message`). Mid-plan commits carry `[no-deploy]`
  so CI tests every push but production only receives the reviewed final state.
- Fix or `markTestSkipped` (with reason) the three known pre-existing failures (G9).
- Acceptance: workflow YAML valid (static review), deploy cannot run on red tests.

## Task 2: Slide 4 — gated operation completion

Effort: high. Files: `app/Services/OperationLifecycleService.php`,
`app/Filament/Resources/ActiveProjectResource.php`, API controller exposing completion (grep
`markCompleted`), seeder + revoke migration, lang files, tests.

- Add `completionBlockers(Project): array` returning translated reasons; `markCompleted`
  throws DomainException listing them when non-empty (R4 conditions). Discover the real
  models: work orders (`WorkOrderService`, finish = "انتهاء التصنيع"), quality sheets
  (`QualitySheetService`, pass state), delivery vouchers (`DeliveryVoucherService`, activated).
- Filament action: visible with permission; disabled + tooltip listing blockers when blocked.
- Same guard enforced in the API path.
- Seeder: no Sales*/Sales_Officer/Sales_Head/Sales_Manager role holds `operations.complete`;
  idempotent migration revokes it on prod, and also from any direct user permission.
- Tests: each blocker individually, happy path, sales role cannot complete (403/hidden).

## Task 3: Slide 1.2 — engineer contact fields

Effort: medium. Files: migration, `app/Models/Project.php`, `ProjectResource` form/infolist,
API Store/Update requests + Resource, lang, tests.

- Add nullable `engineer_phone`, `engineer_email` (email rule), `engineer_office`,
  `engineer_site` (موقع) next to existing `engineer_name`. Grouped in an "بيانات المهندس"
  section shown in the project's attachments area (where the deck asks) and in the project form.
- Tests: create/update via Filament form + API validation.

## Task 4: Slide 1.1 — customer communication inside attachments + timeline

Effort: high. Files: `CustomerInteraction` model/resource/relation manager,
`app/Services/OperationTimelineService.php`, project attachments UI, API, tests.

- In the project's attachments area add a "التواصل مع العميل" block: quick "تسجيل مكالمة /
  ملاحظة" action (date-time, type call/meeting/message, summary/details, next follow-up)
  reusing `CustomerInteraction` (do not create a parallel model), plus the latest entries list.
- Timeline: each interaction appears as an event on the operation timeline (`ProjectsTimeline`
  page slide-over and `/projects/{id}/timeline` API) as a separate `interactions` feed in the
  payload — do NOT change the 18 stage keys (Flutter depends on them).
- Keep `forMany()` N+1-free (eager load counts/latest).
- Tests: interaction created from project page is linked; appears in timeline payload.

## Task 5: Slide 1.3 — restricted drawing attachments

Effort: high (security). Files: `app/Enums/AttachmentCategory.php`, attachment policy /
queries / download route, Filament upload + list, API attachments endpoints, seeder +
grant migration, tests.

- `AttachmentCategory::isRestricted()` for the three categories (R3).
- Enforce server-side everywhere attachments are listed, counted, previewed, downloaded or
  uploaded (Filament + API + any signed/download URLs + timeline payload counts): without
  permission they are excluded and direct access returns 403. Upload options hide these
  categories for users without the permission.
- Tests: without permission — list excludes, download 403, API excludes; with permission — sees.

## Task 6: Slides 2-3 (a) — split inventory accounts

Effort: medium. Files: `database/seeders/ChartOfAccountsSeeder.php`, `config/operations.php`,
settings UI if account codes are configurable there, migration if needed, tests.

- Add accounts per R1 with correct type/section so the balance sheet and trial balance
  include them. Add an `InventoryAccounts` small resolver (service or config accessor) used
  by Tasks 7-8. Tests: seeder idempotent; resolver returns accounts.

## Task 7: Slides 2-3 (b) — automatic inventory journals

Effort: xhigh. Files: `AdditionVoucherService`, `IssueVoucherService`, `ReturnVoucherService`,
`DepreciationVoucherService`, `WorkOrderService` (finish), `CostCenterClosingService`,
`PurchaseInvoicingService`, `OperationCostService` (exclusions), new
`InventoryJournalService`, migration for source-reference columns, tests.

On activation/approval of each document (same hook point where stock movement is finalized):
1. Addition voucher: Dr 1310 / Cr supplier or treasury (R5) at voucher value.
2. Issue voucher (to a manufacturing order/operation): Dr 1320 / Cr 1310 at issued cost.
3. Return voucher (back to store): Dr 1310 / Cr 1320 — mirror of issue.
4. Depreciation/scrap voucher: credit side becomes the correct store account (1310 for raw,
   1320 for WIP scrap) instead of 1300.
5. Manufacturing finish: Dr 1330 / Cr 1320 at the order's accumulated material cost
   (issues − returns − WIP scrap).
6. Delivery: `CostCenterClosingService` credits 1330 instead of 1300. Keep its three
   accounting decisions (closing base, 5070 excluded from `ledgerExpenses`, cumulative +
   reversing corrections).
- Tag lines with `project_id` only where it does not double-count in
  `OperationCostService::ledgerExpenses` (add exclusions for 1310/1320/1330 like 5070).
- Idempotency per source document (G8); cancellation/reversal of a document posts a reversing entry.
- Tests: one per flow + full chain (purchase → issue → finish → deliver) ending with
  1310/1320/1330 balances correct and trial balance balanced; no double cost in cost file.

## Task 8: Slide 2 — inventory valuation page

Effort: high. Files: new Filament page `InventoryValuation` (finance group), blade under
`resources/views/filament/`, API endpoint `/reports/inventory-valuation`, permission
(`reports.inventory_valuation`, Finance + General_Manager + Admin, grant migration), lang, tests.

- Cards: total inventory value + each of 1310/1320/1330 ledger balance as of a date filter,
  plus stock-card valuation for raw store and a per-operation WIP breakdown, with the
  difference between ledger and stock-card value shown (reconciliation hint per R2).
- Tests: page access by permission, numbers match posted journals.

## Task 9: Full E2E suite in CI

Effort: high. Files: `tests/e2e/` (Playwright, TypeScript), `database/seeders/E2eSeeder.php`,
`.github/workflows/tests.yml` e2e job.

- CI job: build assets (`npm ci && npm run build`), sqlite file DB, `migrate --seed` +
  `E2eSeeder` (users per role incl. أسماء/sales/factory manager/finance, one operation with
  manufacturing order), `php artisan serve`, Playwright chromium headless.
- Specs cover everything in this plan: completion gate (blocked → unblocked; sales cannot),
  engineer fields, customer call log → timeline, restricted drawings per user, full inventory
  chain journals + valuation page numbers, plus smoke of login/dashboard/main resources.
- Upload Playwright report as artifact on failure. Deploy gated on this job too.

## Task 10: Final review, push, deploy, prod verification (controller)

- Final whole-branch review (Sonnet, high effort) + one fix wave.
- Push main → `tests` (phpunit + e2e) → deploy. Watch with `gh run watch`.
- On prod via `ssh electrotech` (read-only checks): migrations ran, accounts 1310-1330 exist,
  permissions revoked/granted, app responds. No data mutation without asking.
