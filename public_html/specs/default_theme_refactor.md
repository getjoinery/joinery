# Default Theme Refactor Spec

## Overview

The current "default theme" looks unprofessional and doesn't mix cleanly with branded themes. When a branded theme is active, base views (login, signup, password reset, etc.) rendered from `/views/` lose their styling because the CSS file they depend on is never loaded. Even if we loaded it, its global class names (`.card`, `.btn`, `.auth-page`) would collide with the branded theme's own styles.

This spec refactors the default theme into an **isolated, token-driven UI kit** that can render correctly inside any active theme's header/footer without CSS collisions. The visual redesign happens in the same pass — we rewrite `custom.css` once, scoped and tokenized from the start, rather than scoping the old styles and then discarding them.

## Current State

### Theme directory layout

- `/theme/default/` — a structurally inert placeholder. Empty `includes/`, no `views/`. Not a real fallback.
- `/theme/{brand}-html5/` — branded themes with their own `PublicPage.php`, `views/`, and CSS.
- `/views/` — the **actual** default views (login, signup, password reset, profile scaffolding, etc.).
- `/assets/css/base.css` — framework utilities (grid, flexbox, buttons, spacing). Always loaded.
- `/assets/css/custom.css` — styles for auth pages and general components (`.auth-page`, `.auth-card`, `.auth-logo`, etc.). **Only loaded by the generic fallback `/includes/PublicPage.php`**, not by any branded theme.

### The asymmetry

View resolution cascades correctly:
```
theme/{theme}/views/ → plugins/{plugin}/views/ → views/ → 404
```

Stylesheet loading does **not** cascade. Each theme's `PublicPage` class is solely responsible for loading CSS, and branded themes only load their own theme CSS plus `base.css`. A base view rendered inside a branded theme context gets none of the default-theme component styles.

### Concrete failure case

When `empoweredhealth-html5` is the active theme and a user hits `/login`:

1. View resolves to `/views/login.php` (base fallback — correct).
2. `PublicPage.php` resolves to `/theme/empoweredhealth-html5/includes/PublicPage.php`.
3. That file loads `empoweredhealth.min.css` + `base.css`. It does **not** load `custom.css`.
4. `login.php` markup uses `.auth-page`, `.auth-card`, `.auth-logo` — all defined only in `custom.css`.
5. Result: unstyled auth card inside branded header/footer.

### Why "just always load custom.css" isn't the answer

Loading `custom.css` globally solves the missing-styles problem but introduces collisions — branded themes define their own `.card`, `.btn`, etc., and the two stylesheets fight. The default theme needs to be **isolated**, not globalized.

## Visual Design Exploration

10 candidate visual directions have been built as self-contained HTML mockups and live at:

**Gallery:** `https://joinerytest.site/theme-sources/default-theme-variants/`

**Source files:** `/home/user1/theme-sources/default-theme-variants/` (symlinked to `public_html/theme-sources/`, gitignored)

### Variants

| # | Name | Palette | Typography | Character |
|---|------|---------|------------|-----------|
| 01 | Pure Slate | grayscale + slate-blue | Inter | baseline neutral |
| 02 | Warm Paper | ivory/cream, dusty blue | Inter + Fraunces | warm, literary |
| 03 | Cool Mist | cool gray, muted teal | system stack | no-fuss default |
| 04 | Linen | sand/espresso, olive | Lora | handcrafted |
| 05 | Quiet Navy | near-white, muted navy | IBM Plex Sans | institutional |
| 06 | Soft Plum | warm gray, muted plum | Inter + Source Serif | sophisticated |
| 07 | Editorial Minimal | mono, dusty red-orange | Source Sans + EB Garamond | magazine-like |
| 08 | Friendly Rounded | warm neutral, sage | Manrope, larger radii | approachable |
| 09 | Compact Pro | gray, muted indigo | Inter, tight density | data-heavy |
| 10 | Airy Modern | near-white, dusty rose | Plus Jakarta Sans | spacious, airy |

### Key property: markup identity

All 10 variants use **identical HTML markup** (1072 lines each). They differ only in:
1. The `:root` token block
2. The Google Fonts `<link>` tags
3. The variant identifier banner (name + accent swatch + font-pairing label)

This is a deliberate proof of the token-driven architecture: if reskinning the entire kit is genuinely a matter of changing ~20 token values, identical markup across 10 different-looking variants is the demonstration. Any variant-specific CSS outside the token block would falsify the thesis.

### What each variant demonstrates (component inventory)

Each variant is a kitchen-sink style guide showing every component the default theme must support. This is the authoritative inventory for Phase 2 implementation:

1. Top navigation bar (logged-out and logged-in states)
2. Typography scale — h1–h6, body, muted, blockquote, inline code, links
3. Auth card (login form) — primary component; give it presence
4. Profile tab menu (horizontal, 6 tabs)
5. Content panel — heading, meta row, body, action row
6. List grid, 3-column (events)
7. List grid, 2-column (products)
8. Data table — orders-style with status badges
9. Form kit — text/email/tel/select/textarea/date/radio/checkbox inputs, error state, help text, action row
10. Button variants — primary/secondary/outline/ghost/disabled/destructive, three sizes, with-icon
11. Alerts — success/info/warning/error, each with inline SVG icon
12. Badges and pills — status badges, category tags
13. Empty state — icon + message + action
14. Cart summary — line items, subtotal/tax/total, checkout CTA
15. Pagination
16. Breadcrumbs
17. Footer — three-column link list + copyright

## Proposed Architecture

Treat the default theme as an **isolated, token-driven UI kit** rather than a set of global styles.

### 1. Scope all default-theme CSS under a namespace class

Every rule in the rewritten `custom.css` lives under a scoping class — working name `.jy-ui` (final name TBD during implementation).

```css
.jy-ui .auth-card { ... }
.jy-ui .btn-primary { ... }
```

Every base view wraps its content in that class:

```php
<div class="jy-ui">
    <!-- auth card markup -->
</div>
```

Branded themes' global `.card` / `.btn` can't reach inside the scope, and default styles can't leak out into branded header/footer content.

### 2. Use CSS custom properties for themeable values

All themeable values are exposed as tokens at the top of `custom.css`. The token structure below was validated against the 10 mockup variants — it's rich enough that every variant reskins purely via token changes, and semantic enough that branded themes can override without understanding the internals.

```css
.jy-ui {
    /* ═══ NEUTRALS ═══ */
    --color-bg: #ffffff;
    --color-surface: #f7f8fa;         /* cards, panels */
    --color-surface-alt: #eff1f5;     /* table stripes, subtle bg */
    --color-border: #e1e4ea;
    --color-border-strong: #c8ccd4;
    --color-text: #1a1d23;
    --color-text-muted: #5a6170;
    --color-text-subtle: #8990a0;

    /* ═══ ACCENT ═══ */
    --color-primary: #5b7a99;
    --color-primary-hover: #4a6886;
    --color-primary-text: #ffffff;
    --color-link: #4a6886;

    /* ═══ SEMANTIC (with bg pairs for alerts) ═══ */
    --color-success: #...;   --color-success-bg: #...;
    --color-warning: #...;   --color-warning-bg: #...;
    --color-danger:  #...;   --color-danger-bg:  #...;
    --color-info:    #...;   --color-info-bg:    #...;

    /* ═══ TYPOGRAPHY ═══ */
    --font-sans: 'Inter', system-ui, sans-serif;
    --font-display: 'Inter', system-ui, sans-serif;  /* headings — can differ from body */
    --font-mono: ui-monospace, 'SF Mono', Menlo, monospace;
    --text-xs: 0.75rem;    --text-sm: 0.875rem;    --text-base: 1rem;
    --text-lg: 1.125rem;   --text-xl: 1.375rem;    --text-2xl: 1.75rem;
    --text-3xl: 2.25rem;   --text-4xl: 3rem;
    --leading-tight: 1.25;  --leading-base: 1.6;
    --tracking-tight: -0.01em;  --tracking-normal: 0;

    /* ═══ SPACE SCALE ═══ */
    --space-1: 0.25rem;  --space-2: 0.5rem;  --space-3: 0.75rem;
    --space-4: 1rem;     --space-5: 1.5rem;  --space-6: 2rem;
    --space-8: 3rem;     --space-10: 4rem;

    /* ═══ RADIUS ═══ */
    --radius-sm: 4px;  --radius-md: 6px;  --radius-lg: 10px;  --radius-full: 999px;

    /* ═══ SHADOW ═══ */
    --shadow-sm: 0 1px 2px rgba(20,25,35,0.04);
    --shadow-md: 0 2px 8px rgba(20,25,35,0.06);
    --shadow-lg: 0 6px 24px rgba(20,25,35,0.08);

    /* ═══ CONTROL HEIGHTS ═══ */
    --control-h-sm: 32px;  --control-h-md: 40px;  --control-h-lg: 48px;
}

.jy-ui .btn-primary {
    background: var(--color-primary);
    border-radius: var(--radius-md);
    font-family: var(--font-sans);
    height: var(--control-h-md);
}
```

**Hard rule for implementation:** no hex codes, px values, or font-family strings appear in any rule outside the `:root` block. This is the same rule enforced on the 10 mockups (verified via grep — each mockup has exactly 25 hex occurrences, all inside the token block or in unavoidable inline content).

**Density is tokenized too.** Variant 09 (Compact Pro) demonstrates tighter density purely by scaling down `--space-*` and `--control-h-*` values. Variant 10 (Airy Modern) scales them up. Density is not a separate code path — it's a token adjustment.

**Focus ring pattern** (used throughout the mockups):
```css
.jy-ui input:focus, .jy-ui button:focus-visible {
    outline: none;
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-primary) 25%, transparent);
}
```
`color-mix()` keeps the token surface minimal by deriving the focus-ring translucency from the primary color. Supported in all evergreen browsers.

Branded themes opt into their brand by overriding tokens on `.jy-ui` or at `:root`:

```css
:root {
    --color-primary: #c41e3a;
    --font-sans: 'Lato', sans-serif;
    --radius-md: 12px;
}
```

A login page rendered inside a branded theme picks up brand colors, fonts, and shape automatically.

### 3. Always load the default UI kit CSS from `PublicPageBase`

Once the CSS is scoped, it's safe to load unconditionally. Modify `PublicPageBase::render_base_assets()` to include the rewritten file alongside `base.css`. The legacy fallback-only loading in `/includes/PublicPage.php` can be removed.

### 4. Redesign happens in the same rewrite

The rewritten `custom.css` is a new visual design, not a re-scoping of the old one. Redesign scope (to be detailed in a follow-up pass before implementation):

- Auth pages (login, signup, password reset, account activation, 2FA)
- Basic layout primitives (card, panel, form, button, input, alert, badge)
- Profile scaffolding (tab menu, section headers)
- Empty states and loading indicators

Out of scope for this spec: admin interface (uses Bootstrap), plugin-owned views with their own styles, the commercial branded themes.

## Sequencing Decision

Redesign and architecture ship together in one pass.

- **Architecture-first** would mean scoping styles we're about to delete.
- **Redesign-first** would mean designing globally-scoped CSS that can't be tested in a branded theme context until architecture lands.
- **Together** means one rewrite, tested from day one inside a branded theme.

Tradeoff accepted: bigger single push, no incremental isolation win. If a specific client needs the isolation fix sooner, architecture-first is the fallback.

## Alternatives Considered

**CSS Cascade Layers (`@layer default, theme`)** — lighter than a scoping class; themes override via layer precedence without needing a wrapper element. Rejected for now because (a) it's harder to reason about than a literal class prefix, (b) requires dropping pre-2022 browser support, and (c) doesn't prevent default styles from leaking into branded content — only controls override precedence.

**Shadow DOM / web components** — true isolation but overkill for server-rendered PHP views and breaks form submission ergonomics.

**Per-theme copies of `custom.css`** — rejected; multiplies maintenance and defeats the point of a default UI kit.

## Implementation Plan

### Phase 1 — CSS architecture and token system

- Define the final scope class name (`.jy-ui` or similar).
- Define the token vocabulary (color, typography, spacing, radius, shadow, z-index).
- Document token names and semantic intent so branded themes know what to override.

### Phase 2 — Rewrite `custom.css`

- New visual design based on the **selected variant** from `/theme-sources/default-theme-variants/` (variant choice is the main open question below).
- Written scoped under the namespace class from the start.
- All themeable values reference custom properties — same hard rule as the mockups: no raw hex/px/font-family in any rule outside `:root`.
- The selected variant's HTML markup can be used near-verbatim for the component CSS — the mockups already render the full inventory with production-shape markup.
- Old `custom.css` deleted at the end of this phase, not before.

### Phase 3 — Wrap base views

- Add the scoping class wrapper to every file in `/views/` that depends on default-theme styles.
- Inventory and document which views are in scope.

### Phase 4 — Loading integration

- Modify `PublicPageBase::render_base_assets()` to load the new UI kit CSS unconditionally.
- Remove the legacy `custom.css` load from `/includes/PublicPage.php`.
- Verify branded themes (start with `empoweredhealth-html5`) render base views correctly.

### Phase 5 — Documentation

- Update `docs/theme_integration_instructions.md` with:
  - The token vocabulary and how branded themes override it.
  - The scope class and the fact that base views are self-contained.
  - Removal of the old "you must manually include `joinery-custom.css` in your theme" pattern — no longer needed.

### Phase 6 — Branded theme cleanup (optional, per-theme)

- For each branded theme, audit whether it was working around the old conflict (e.g., redefining auth styles locally). Remove workarounds and set brand tokens instead.

## Content Conventions (for base views)

Observed while building mockups — carry these into Phase 3 when wrapping views:

- Use realistic sample content in mockups and previews (event names, user names, prices, dates), never lorem ipsum — it reads as unfinished and makes evaluation harder.
- Site name placeholder: "Joinery" is acceptable where the branded theme hasn't set one.
- Icons throughout: inline SVG only, no icon fonts, no external sprite sheets. The default theme ships its own minimal icon set.

## Open Questions

- **Which variant to build from.** The 10 mockups at `/theme-sources/default-theme-variants/` are candidates. Decision needed before Phase 2.
- **Final scope class name.** Working name is `.jy-ui`. Alternatives: `.joinery-ui`, `.jy-default`. Mockups currently use unprefixed class names inside their own `<style>` — needs prefixing when lifted into `custom.css`.
- **Token naming convention.** Mockups use semantic grouping (`--color-primary`, not `--jy-primary` or `--jy-color-primary`). This keeps tokens readable inside rules. Decide whether to keep semantic-only or add `--jy-` prefix for collision safety when tokens are overridden at `:root`.
- **Dark mode.** Not addressed in the current mockups. Decide whether to ship a parallel dark-token set (toggled via `[data-theme="dark"]` or `@media (prefers-color-scheme: dark)`) in the same pass or defer.
- **Branded-theme opt-in mechanism.** Two options: (a) branded themes override tokens at `:root` (simple but leaks to other scoped kits if they exist later); (b) branded themes override on `.jy-ui` directly (tighter scope). Recommend (a) for simplicity unless a concrete reason emerges.

## Files Touched (anticipated)

| File | Action |
|------|--------|
| `/assets/css/custom.css` | Full rewrite — scoped, tokenized, redesigned |
| `/includes/PublicPageBase.php` | Load new UI kit CSS in `render_base_assets()` |
| `/includes/PublicPage.php` | Remove legacy `custom.css` load |
| `/views/login.php`, `/views/signup.php`, `/views/password_recovery.php`, etc. | Add scope class wrapper |
| `docs/theme_integration_instructions.md` | Document tokens, scoping, removal of manual include pattern |
| `/theme/default/` | Evaluate whether this placeholder directory still serves a purpose |
