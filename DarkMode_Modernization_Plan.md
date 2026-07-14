# Dark Mode Modernization Plan — "Charcoal & Ember"

**Goal:** Replace the flat, default-Slate dark theme with a modern 2026 dark
system that has real elevation, warm-charcoal surfaces, glowing ember accents,
and beveled hairline borders — cohesive with the brand terracotta (`#D9723B`).

**Scope:** Dark mode only. Light mode is untouched. All work is CSS in
`resources/css/filament/admin/theme.css` (+ a small optional widget view tweak).
No PHP/behavioral changes. Direction chosen by user: **Charcoal & Ember**.

---

## Why the current dark mode reads as "basic"

- Surfaces (`slate-900`) sit almost on top of the background (`slate-950`) → no
  perceived elevation / depth.
- No top-edge highlight (bevel) → misses the "glass/premium" cue every modern
  dark dashboard uses.
- Cold Slate blue clashes tonally with the warm terracotta brand → feels like an
  untouched default.
- Accent (orange) never glows or pops → no focal hierarchy.
- No colored shadows, gradients, or hover lift.

---

## Design tokens (the palette)

Introduce a semantic token layer inside a `.dark` scope so surfaces are named by
role, not by hard-coded slate values. Warm charcoal ramp (slight red/amber
undertone), ember accent, warm off-white text.

```
Role            Value       Use
--------------  ----------  -------------------------------------------
bg              #100E0C     app / main content background
sidebar-bg      #141210     sidebar (a hair above bg)
surface-1       #1A1714     cards, sections, widgets, topbar
surface-2       #221E1A     inputs, hover, raised
surface-3       #2A251F     elevated / active row / dropdowns
border-hairline rgba(245,235,225,0.08)   default 1px borders
border-strong   rgba(245,235,225,0.12)   dividers that need to read
bevel-top       rgba(255,240,225,0.06)   inset top highlight (elevation)
text            #F4EFE8     primary text / values
text-muted      #B8AFA4     descriptions, labels
text-faint      #8A8178     meta, placeholders
ember           #E48745     primary accent (already primary-400)
ember-glow      rgba(228,135,69, .28)    colored shadow / focus ring
```

Add these as CSS custom properties. Because Tailwind v4 `@theme` vars are global,
scope the *surface* tokens under `.dark` (light mode keeps its cream values):

```css
.dark {
    --surface-bg:        #100E0C;
    --surface-sidebar:   #141210;
    --surface-1:         #1A1714;
    --surface-2:         #221E1A;
    --surface-3:         #2A251F;
    --border-hairline:   rgb(245 235 225 / 0.08);
    --border-strong:     rgb(245 235 225 / 0.12);
    --bevel-top:         rgb(255 240 225 / 0.06);
    --dark-text:         #F4EFE8;
    --dark-text-muted:   #B8AFA4;
    --ember-glow:        rgb(228 135 69 / 0.28);
    /* reusable elevation shadow w/ top bevel */
    --elev-1: 0 1px 2px rgb(0 0 0 / .40), 0 4px 14px -4px rgb(0 0 0 / .35),
              inset 0 1px 0 var(--bevel-top);
    --elev-2: 0 2px 4px rgb(0 0 0 / .45), 0 10px 28px -8px rgb(0 0 0 / .45),
              inset 0 1px 0 var(--bevel-top);
}
```

---

## Implementation steps (in `theme.css`)

### 1. Base background
- `.dark html, .dark body` and `.dark .fi-main*/.fi-layout/.fi-body` →
  `var(--surface-bg)` (`#100E0C`) instead of `--color-slate-950`.

### 2. Sidebar
- `.dark .fi-sidebar` / `.fi-sidebar-header` → `var(--surface-sidebar)`,
  border `var(--border-hairline)`.
- Nav item hover → `var(--surface-2)`.
- **Active item:** keep ember fill (`--color-primary-500`) but add glow:
  `box-shadow: 0 2px 12px -2px var(--ember-glow), inset 0 1px 0 rgb(255 255 255 /.12)`.
- Group labels → `var(--dark-text-muted)`.

### 3. Topbar
- `.dark .fi-topbar` → translucent charcoal `rgb(26 23 20 / 0.72)` + existing
  `backdrop-filter: blur(8px)`, bottom border `var(--border-hairline)`.

### 4. Cards / sections / widgets  ← the biggest visual win
- `.dark .fi-section, .fi-fo-section, .fi-wi-stats-overview-stat, .fi-ta-ctn` →
  - background `var(--surface-1)`
  - border `var(--border-hairline)`
  - `box-shadow: var(--elev-1)`  (this is what creates depth + the top bevel)
- Add a subtle hover lift on stat cards:
  `.dark .fi-wi-stats-overview-stat:hover { box-shadow: var(--elev-2);
   transform: translateY(-1px); transition: .18s ease; }`

### 5. Stat cards — make numbers pop (the dashboard hero)
- Give each stat card a faint top-lit gradient surface:
  `background: linear-gradient(180deg, #1E1A16 0%, var(--surface-1) 60%);`
- The description icon chip: tint with the stat's semantic color at low alpha so
  primary/warning/info/success/danger each get a colored glow dot. Filament
  applies `text-{color}` utilities to the icon already — we reinforce with a
  soft `filter: drop-shadow(0 0 6px currentColor)` at low opacity on
  `.dark .fi-wi-stats-overview-stat-description-icon`.
- Ensure the big value uses `var(--dark-text)` and the primary-colored stat's
  value glows slightly.

### 6. Inputs & buttons
- `.dark .fi-input, .fi-select-input, .fi-textarea` → background `var(--surface-2)`,
  border `var(--border-hairline)`.
- Focus ring → ember: `box-shadow: 0 0 0 2px var(--surface-bg), 0 0 0 4px var(--ember-glow)`.
- Primary `.fi-btn` in dark → keep ember fill + `box-shadow: 0 2px 12px -2px var(--ember-glow)`.

### 7. Tables & dividers
- `.dark .fi-ta-header / .fi-ta-row` borders → `var(--border-hairline)`.
- Row hover → `var(--surface-2)`.

### 8. Text ramp (safety-net section)
- `.dark` headings → `var(--dark-text)`; sub/descriptions/breadcrumbs →
  `var(--dark-text-muted)`. Replaces the current cool gray-400.

### 9. Scrollbar
- Warm the dark thumb: `rgb(245 235 225 / 0.12)`, hover ember.

---

## Files touched
- `resources/css/filament/admin/theme.css` — all of the above (main work).
- (Optional) `app/Filament/Widgets/StatsOverview.php` — no change needed; colors
  already set via `->color()`. Only touch if we want per-card accent bars via
  `extraAttributes`.

## Build & verify
1. `npm run build` (Vite compiles the Filament theme).
2. Load `/admin` in dark mode → verify: cards visibly float above bg, top bevel
   visible, ember active nav glows, stat numbers pop, no invisible text.
3. Toggle to light mode → confirm 100% unchanged.
4. Spot-check a form page (inputs/focus ring) and a table page (row hover).

## Risk / rollback
- Pure CSS, dark-scoped → light mode can't regress. Rollback = revert one file.
- Watch: hard-coded `--color-slate-*` references elsewhere; grep before/after.

## Out of scope (future)
- Light-mode refresh, chart/widget color theming, per-department accent hues.
