---
name: dark-theme-tokens
description: Dark mode uses "Charcoal & Ember" warm token system; custom blade views must use the tokens, not cold gray/slate utilities
metadata:
  type: project
---

Dark mode was modernized to a warm "Charcoal & Ember" system. Tokens are defined under `.dark` in `resources/css/filament/admin/theme.css` (plan: `DarkMode_Modernization_Plan.md`):
- Surfaces: `--surface-bg`, `--surface-sidebar`, `--surface-1` (cards), `--surface-2` (inputs/hover/raised), `--surface-3`
- Borders: `--border-hairline`, `--border-strong`, `--bevel-top`
- Text: `--dark-text`, `--dark-text-muted`, `--dark-text-faint`
- Accent glow: `--ember-glow`; elevation: `--elev-1`, `--elev-2`

**Why:** custom blade pages/widgets originally hardcoded cold `dark:bg-gray-900`, `dark:bg-white/5`, `dark:border-white/10`, `dark:text-gray-400` etc., which clash with the new warm charcoal — they don't follow the theme.

**How to apply:** in custom blade views use Tailwind arbitrary values pointing at the tokens, e.g. `dark:bg-[var(--surface-2)]`, `dark:border-[var(--border-hairline)]`, `dark:text-[var(--dark-text-muted)]`. Keep semantic tints (success/danger/primary/warning/info `-500/10`, `-400`) as-is — they harmonize. Files fixed in this round: the 4 `filament/pages/*` report pages, `items/stock-card.blade.php`, `items/quick-view.blade.php`. Run `npm run build` after changes so Tailwind generates the arbitrary-value classes.
