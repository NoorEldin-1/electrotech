# E2E Test Report — Response

**Report:** End-to-End Test Report, ElectroTech Orwa, 1 August 2026
**Status:** all 11 findings addressed. 30 new regression tests, all passing (suite: 511 passed).

---

## 3. Functional Bugs

### 3.1 — Work order marked "Completed" with inconsistent quantity/date data `HIGH`

**Cause:** `planned_quantity` defaulted to `0` and was not required on the form; planned dates were optional. No service-layer check existed, so an order could pass Draft → Pending → InProgress → QA → Completed with an empty plan. Since planned quantity is the denominator of efficiency, waste % and cost variance, a zero plan silently zeroes all three.

**Fix:** Added `WorkOrderService::assertPlanIsComplete()`, enforced at the two gates that lead into manufacturing — `approveOrder()` (PMO release) and `start()` (catches legacy rows that reached Pending another way). Guarding the entrance rather than completion means materials are never issued against an unplanned order. Form-side: `planned_quantity` is now required with `gt:0`; both planned dates are required, with end ≥ start.

Existing completed records are untouched; the guard prevents recurrence.

---

### 3.2 — Validation messages are icon/colour only

**Cause:** Filament renders the native HTML `required` attribute on its inputs. The **browser** therefore blocks the submit before Livewire runs, so server-side validation never executes and Filament's own `<p class="fi-fo-field-wrp-error-message">` is never emitted. What remained was the `:invalid` border plus, for `type="email"`, the browser's native English bubble.

**Fix:** New `public/js/inline-validation.js`, loaded panel-wide. It keeps the native constraints (fastest possible feedback) but takes over reporting:

- cancels the `invalid` event, suppressing the browser bubble;
- renders a localized message into the field's own wrapper using Filament's exact markup and classes, so both themes and RTL are inherited;
- sets `aria-invalid` on the control — the colour-only signalling is gone;
- focuses and centre-scrolls the **first** offending field.

Messages live in `lang/{ar,en}/validation.php → client`. Server-side errors still take precedence when present.

---

## 4. UI Issues

### 4.1 — Global search overlay: low contrast, overlaps content, lingers after navigation

**Cause (contrast):** the panel ships as `bg-white … ring-gray-950/5`; only the dark variant had been re-skinned. On our warm canvas, a white sheet with a 5%-opacity edge sitting over white content cards has effectively no boundary.
**Cause (lingering):** the global-search Livewire component keeps its `search` state across a `wire:navigate`, so the results panel survived the navigation.

**Fix:** explicit surface, a border that reads, and floating elevation in both themes; height capped against the viewport (`min(24rem, 100vh - 9rem)`) and scrollable, replacing the fixed `max-h-96`. A `livewire:navigated` listener clears and blurs the search input.

---

### 4.2 — Low-contrast row-action menu items

**Cause:** the existing rule coloured `.fi-dropdown-list-item` — the `<button>`. The visible text lives in the nested `.fi-dropdown-list-item-label`, which kept Filament's shipped `text-gray-700 / dark:text-gray-200`.

**Fix:** the label is now coloured from the full-strength text token in both themes, with leading icons one step down and brand-tinted on hover. Semantic items (danger deletes, etc.) are excluded so their meaningful colour survives.

---

### 4.3 — Unstyled, non-localized 404 page

**Cause:** no `resources/views/errors/` existed, so Laravel's built-in page was served.

**Fix:** branded, RTL, localized pages for 403 / 404 / 419 / 429 / 500 / 503 over a shared layout, each offering a route back to the panel and to the platform guide. The layout is deliberately self-contained — no Vite manifest, no Livewire, no Filament layout — so it renders even mid-deploy. Note: an unmatched URL never reaches the session middleware, so these default to Arabic (the platform's working language) and honour an explicitly selected locale when a session exists.

---

### 4.4 — Success toast overlaps the floating navigation pill

**Cause:** the notification stack is `fixed inset-4`, i.e. `top: 1rem`. The topbar pill starts at `0.875rem` and is `3.5rem` tall — a guaranteed collision.

**Fix:** the stack now starts clear of the pill, with the value tracking the pill's own geometry and adjusted at each breakpoint.

---

## 5. UX Issues

### 5.1 — Dashboard KPI cards show empty placeholders before populating

**Cause:** the widget is lazy, and no `placeholder()` was defined — so Livewire rendered its default empty `<div>` for the duration of the round-trip.

**Fix:** a skeleton whose wrapper, grid and card classes are copied verbatim from Filament's own stats views and whose bars are sized to the text they stand in for, so the real data replaces it with zero layout shift. Shimmer animation, suppressed under `prefers-reduced-motion`.

---

### 5.2 — Validation feedback requires a server round-trip

Same root cause and same fix as **3.2**. Once a field has been touched, it re-validates on every keystroke entirely on the client — no server call.

---

### 5.3 — Inconsistent post-save redirect across modules

**Cause:** only 5 of 24 resources overrode `getRedirectUrl()`. The rest used Filament's default, which lands a create on the record's Edit page.

**Fix:** all 38 Create and Edit pages now return to the resource list after saving — the rule the 5 already followed. `PostSaveRedirectConsistencyTest` fails the build if a new resource reintroduces the split.

---

### 5.4 — Relationship select does not filter; empty default date ranges

**Cause (select):** Project → Client combined `searchable()` with `preload()`. Preloading loads every option up front and hands filtering to the browser's fuzzy matcher, which barely narrows Arabic names.
**Cause (dates):** both finance reports opened on the current calendar month regardless of whether anything had been posted into it.

**Fix (select):** `preload()` dropped — this is now a real server-side search, extended to cover name, contact person, phone, email and tax number, with the phone shown in the option label to disambiguate similar names.
**Fix (dates):** shared `DefaultsToPeriodWithLedgerData` concern used by both Journal Daybook and General Ledger — opens on the current month when it holds posted entries, otherwise on the month of the most recent posting. An empty ledger still opens on the current month.

---

## 8. Data-Quality Note

### Duplicate email / phone across customers and suppliers — confirmed

**Cause:** no uniqueness check on either field, in either module.

**Fix:** email and phone are now unique per table (customer and supplier remain independent — the same company can legitimately be both). Soft-deleted records are excluded, so an archived party does not hold its contact details hostage. The same guard applies to the inline "create customer" shortcut inside Projects, which would otherwise have been a back door.

The phone check deliberately compares the **normalized** value rather than the raw input. The platform accepts Arabic-Indic numerals and normalizes on save, so a stock uniqueness rule would have missed a duplicate typed as `٠١٠٠١٢٣٤٥٦٧` against a stored `01001234567` — precisely the users the input exists for.

### Supplier "1% profit-tax exemption" toggle — not reproduced

The toggle does **not** default to ON. The column defaults to `false` (migration `2026_06_17_000002_add_profit_tax_exempt_to_suppliers`) and the form carried no default. The observed record was most likely an existing supplier with the flag set deliberately. We have made the safe default explicit in the form so it cannot drift, and added a test asserting it.

---

## Verification

| Area | Test |
|---|---|
| Work-order plan integrity | `WorkOrderPlanIntegrityTest` (5) |
| Error pages | `ErrorPagesTest` (9) |
| Inline validation + report periods | `UxRegressionTest` (6) |
| Post-save redirect | `PostSaveRedirectConsistencyTest` (2) |
| Duplicate contacts + tax default | `DuplicateContactGuardTest` (8) |

Full suite: **511 passed**. Three failures remain, all pre-existing and unrelated to this work (recorded in `.phpunit.result.cache` before these changes): one stale English label assertion in `ActivityLogTest`, and two in `NetworkResilienceTest` covering a compression middleware that is not currently registered and a ping-endpoint auth expectation.
