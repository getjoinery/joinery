# CSS Adoption Plan — Finish Rolling Out the `.jy-ui` Kit

**Status:** Active — awaiting implementation
**Version:** 2.0
**Policy:** Implements [css_platform_style_contract.md](css_platform_style_contract.md). Read it first — the platform already ships the `.jy-ui` kit (tokens + `.jy-ui`-scoped components + `.jy-*` chrome), loaded on every page by `render_base_assets()`. This document is *how we finish adopting it* across the surfaces that don't use it yet.

## What this is (and isn't)

This is **adoption**, not authoring. The kit exists and is proven on ~49 public
views. The work is:

1. **Fill a couple of kit gaps** (notably a modal/dialog component).
2. **Bring the non-adopting surfaces onto the kit** — admin (0 adoption) and
   components (0 adoption) — by wrapping their content in `.jy-ui` and using kit
   classes.
3. **Sweep inline `style=` and in-view `<style>` blocks** into the kit / utilities.

There is **no "legacy bare-class layer" to delete** — scoped bare classes like
`.jy-ui .btn` *are* the kit's vocabulary. The end state: every platform surface
renders inside `.jy-ui` with no inline `style=` and no in-view `<style>` blocks. No
phase touches the database.

Each phase is independently shippable and visually safe; phases 2–5 chunk per
directory / per component / per plugin so no single sitting is large.

## Scope (measured)

| Work item | Count | Phase |
|---|---|---|
| Kit gaps to fill (modal/dialog; cache-bust → filemtime) | small | 1 |
| Components not on the kit (own `<style>` blocks) | 7 core (+ `ComponentRenderer`) | 2 |
| Admin pages on the kit | 0 of ~48 (+ ~422 inline styles) | 3 |
| Public views: inline styles to sweep (already `.jy-ui`) | ~749 across 59 files | 4 |
| Plugins on the kit | 0 (+ ~228 inline, 8 `<style>` blocks, 31 files) | 5 |
| Themes with duplicated component CSS to retire | getjoinery, joinery-system (+ a few) | 6 |
| `includes/` + `theme/` inline styles | 169 + 67 | 3–6 (with their surface) |

Inline-style total ≈ **1,635**; it is swept per surface, never a blocking cost.

## Phases

Ordered: foundation/gaps first, then surfaces by reuse and isolation, then guards.

### Phase 1 — Kit gaps + foundation (low risk)

- **Add a modal/dialog component to the kit** (`.jy-ui`-scoped) — the calendar
  currently hand-rolls one; this is the main missing piece. Add any missing small
  utility (e.g. a hidden helper) if not already present.
- **Cache-bust → `filemtime()`** on the base assets (and getjoinery's hardcoded
  `?v=9`). The edge CDN keys on the query string, so this busts correctly.
- **Verify the kit loads before the active theme stylesheet**, and that **every page
  routes through `public_header_common()`** — explicitly the 404/error/maintenance
  paths (`display_404_page()` uses its own template + plain-text fallback); fix any
  that bypass it.

**Exit:** kit has a modal; loads before theme on every page incl. error pages;
filemtime busting in place. Zero visual change to existing pages.

### Phase 2 — Components (incl. `ComponentRenderer`, calendar first)

Bring the 7 core components onto the kit: wrap their markup in a `.jy-ui` scope, use
kit classes, and move each `<style>` block's rules into `joinery-styles.css` (a
delimited feature section), then delete the block. **`ComponentRenderer`** stops
injecting a `<style>` block + free-form inline styles — its layout rules become a
`.jy-cl-*` kit section, and the only inline it emits is a custom-property value
(`style="--jy-cl-max-width: …"`).

**Calendar first, as the worked redo:** wrap `/profile/calendar` content in `.jy-ui`,
replace its `<style>` block and the theme-only `.dialog-btn` buttons with the kit's
modal + `.btn`/`.btn-danger`, and drop its inline styles. (Our calendar shipped
non-conformant; this makes it the reference example.)

**Exit:** core components render inside `.jy-ui` with no `<style>` blocks; calendar
uses the kit modal + buttons.

### Phase 3 — Admin (`adm/`)

The largest single chunk and entirely un-adopted. Wrap admin pages in `.jy-ui`, use
kit components, set joinery-system's brand `--jy-*` overrides, and sweep admin inline
styles. Self-contained and internal, so lower blast radius. Chunk by admin area.

**Scope:** ~422 inline across ~48 files; 3 `<style>` blocks.
**Exit:** admin renders inside `.jy-ui`; no admin inline styles or `<style>` blocks.

### Phase 4 — Public views inline sweep

The ~49 public views already opt into `.jy-ui`, so this is mostly removing their
remaining inline `style=` (≈749) in favor of kit classes/utilities, plus wrapping any
stragglers not yet in `.jy-ui`.

**Exit:** `views/` free of inline styles and `<style>` blocks.

### Phase 5 — Plugins

**First build the plugin-CSS mechanism:** a `plugin.json` stylesheet declaration and
a loader in `PluginManager`/`PluginHelper` that links each active plugin's
`assets/css/{plugin}.css` global-when-active, after the kit and before the theme.
Then convert plugins **one at a time**: wrap in `.jy-ui`, move inline styles +
`<style>` block into the plugin's own `.jy-{plugin}-*` stylesheet.
(`inbound_email`'s `mailbox_reader.css` is the first to adopt the declaration.)

**Exit:** the mechanism exists; each plugin renders on the kit via its own declared
sheet; tracked plugin-by-plugin.

### Phase 6 — Theme dedup

Retire the component CSS that getjoinery and joinery-system still define and the kit
now owns — **after confirming each rule is unused** (a theme may style `.btn` outside
`.jy-ui` for its own chrome; keep those). Set their brand `--jy-*` overrides. Sweep
theme-file inline styles.

**Exit:** themes carry chrome + token overrides only; no duplicated kit components.

### Phase 7 — Guardrails

Install a dev/test check so adoption can't regress:

- no `style="` in platform code (short listed exception for server-computed values);
- no `<style>` block in platform views/components;
- platform content regions render inside `.jy-ui`.

Email and user-authored/WYSIWYG areas are excluded (see policy spec, Out of scope).

**Exit:** the rules are enforced; the kit is the single styling path for platform code.

## Ordering rationale

- **Phase 1 is tiny** (a modal + cache-bust) but unblocks the calendar redo and
  guarantees the kit everywhere.
- **Phases 2–6** go by reuse and isolation: components (high reuse, calendar in
  flight) → admin (biggest inline chunk, internal) → public-view sweep → plugins
  (modular, one at a time) → theme dedup (needs care to not break chrome).
- **Phase 7** locks it in.

## Files

See the policy spec's Files section: [css_platform_style_contract.md](css_platform_style_contract.md#files).
