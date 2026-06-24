# CSS Architecture & Platform Style Contract

**Status:** Active — awaiting implementation
**Version:** 2.0

> **v2 note:** the platform already ships a namespaced CSS kit (`.jy-ui`). This spec
> is reframed from "build a contract" to "**adopt and extend the existing kit**."
> The earlier per-class `.jy-btn` namespace idea is dropped in favor of the
> established `.jy-ui` ancestor scope (see Namespacing).

## Goal

Every platform surface — public views, admin, components, plugins — styles itself
against the **existing `.jy-ui` kit**, with **no inline `style=` attributes** and
**no in-view `<style>` blocks**. Themes restyle only their own personality (chrome
+ brand color via tokens). The kit is already built and proven on 49 public views;
the work is finishing its adoption and filling a couple of gaps.

This is an **architecture + cleanup** effort, not a feature. **No database
migration** is involved — "migration" here means a code-refactor pass.

## Current state — the kit already exists

`PublicPageBase::render_base_assets()` loads `/assets/css/joinery-styles.css` (and
`base.css` + `base.js`) on **every** page. `joinery-styles.css` is a mature,
namespaced kit:

- **52 design tokens** (`--jy-*`): full color set (`--jy-color-primary`,
  `--jy-color-danger`, `--jy-color-success`, …), spacing scale (`--jy-space-*`),
  type scale (`--jy-text-*`, `--jy-font-*`, leading/tracking), radii, shadows,
  control heights.
- **A component kit, scoped under `.jy-ui`**: a full button family
  (`.jy-ui .btn` + `-primary/-secondary/-danger/-destructive/-outline/-ghost/`
  `-block/-sm/-lg`), forms (`.form-control/.form-group/.form-label`), cards, alerts,
  badges, tables, tabs, auth-card.
- **Platform chrome** as `.jy-*` classes (`.jy-site-header`, `.jy-site-footer`,
  `.jy-nav-*`, `.jy-breadcrumbs`, `.jy-panel`, `.jy-page-header`, …).
- **Brand theming already wired**: `render_brand_token_overrides()` reads per-site
  settings (`jy_color_primary`, `jy_color_primary_hover`, `jy_color_surface`, …)
  and emits a `:root` override block, so a site brands the whole kit by setting a
  few color settings.

So the "guaranteed cross-theme contract" we set out to build substantially exists.

## What's actually wrong (the real work)

- **Adoption is partial.** ~**49 views** opt into `.jy-ui`, but **0 admin pages and
  0 components** do. Admin and components style themselves ad hoc instead.
- **Inline styles everywhere.** ~**1,635 inline `style=` attributes** across ~186
  files (views 749, adm 422, plugins 228, includes 169, theme 67).
- **In-view `<style>` blocks** (~33), including the 7 core components and the
  `ComponentRenderer` framework, which inject CSS the kit should own.
- **The system modal isn't in the kit.** `JoineryModal` (a native-`<dialog>`
  confirm/alert/prompt API) exists, but its JS and `.dialog-*` CSS live in two themes
  (`joinery-system` + `getjoinery`, duplicated) — not in the global kit. Yet
  `PublicPageBase` calls `JoineryModal.confirm(...)` generically, so on any other theme
  it throws `JoineryModal is undefined`. It needs promoting into the kit (one copy,
  `.jy-ui`-scoped). Several other modals (image picker, cookie consent) are hand-rolled
  separately and should later converge onto it once it gains a generic content mode.
- **Cache-bust is manual.** The base assets bust with a hand-bumped `?v=`, not
  `filemtime`.
- **A few duplications** — getjoinery and joinery-system still define some component
  CSS that the kit now owns.

## The model — two layers (all CSS in stylesheets)

Governing rule: **CSS lives only in stylesheets, never inside a view.** A view or
component holds markup + class names; appearance is defined in a stylesheet, edited
in one place and overridable by the cascade. No in-view `<style>` blocks (see below).

### Layer 1 — the `.jy-ui` kit (global, every page)

`joinery-styles.css`, loaded everywhere by `render_base_assets()`. It holds the
tokens, the `.jy-ui`-scoped component vocabulary, the `.jy-*` chrome classes, a
small utility set, and **feature-specific rules** for individual views/components
(e.g. `.jy-calendar-grid { … }`) — as ordinary rules in the stylesheet, never a
`<style>` block in the view. Reusable patterns become shared components; one-off
layout becomes a delimited feature section. Keeping feature CSS here is the
deliberate answer to "minimize CSS files" without burying anything in a view.

### Layer 2 — theme: personality only

A theme owns its **chrome** (header, footer, nav, hero, typographic character) and
**overrides `--jy-*` tokens** to brand the kit (the brand-token settings already do
this). A theme does **not** redefine kit component classes. The leftover component
definitions in getjoinery / joinery-system are retired once confirmed unused.

## How the kit is namespaced (the decision)

**Ancestor scope, not per-class prefix.** Component rules require a `.jy-ui`
ancestor: `.jy-ui .btn`, `.jy-ui .card`, `.jy-ui .form-control`. A page **opts in by
wrapping its content in `.jy-ui`**; inside that scope the bare classes are styled by
the kit, and a branded theme's own `.btn` *outside* `.jy-ui` is untouched — so there
is no collision. Platform chrome that lives outside any `.jy-ui` content region uses
explicit `.jy-*` classes instead. (The default theme also gates its global type
resets to `body.jy-default`; other themes leave the body classless, so those resets
are inert for them.)

This is the established convention and the immune-by-scope mechanism. We do **not**
introduce a parallel per-class `.jy-btn` namespace — that would duplicate the kit.

## Why not in-view `<style>` blocks

- **Not overridable.** A body `<style>` block renders after the theme stylesheet, so
  it wins at equal specificity — a theme can't restyle it without `!important`.
- **Not editable without forking the view.** Views resolve through the theme chain,
  so changing one value for a theme would mean copying the whole view file.

Feature CSS therefore goes into the kit. A developer changes appearance by editing a
**stylesheet** (the kit for everyone, a theme stylesheet for one theme), never a
view. The only thing that legitimately stays inline is a value the **server computes
at render time** (e.g. a width from data) — rare, and not a styling decision.

**Component self-containment — the tradeoff.** A core component's CSS lives in the
kit, not in the component file, so a core component isn't fully self-contained. That
is the accepted price of "few files + fully overridable," fine for permanent code.
Removable units (plugins) are the exception — below.

## Components, plugins, and removable units

CSS lives with the lifecycle of the thing it styles:

- **Permanent platform code** — core views, core components (`calendar_grid`,
  `tabs`, `accordion`, …), and `ComponentRenderer`'s layout rules — lives as
  delimited sections in `joinery-styles.css` (`.jy-ui`-scoped for kit components,
  `.jy-*` for chrome). Components **opt into the kit by wrapping their markup in a
  `.jy-ui` scope** (today 0 do). Deleting a component means deleting its section (a
  guardrail catches orphans).
- **Plugins (installed/removed independently)** own a stylesheet —
  `plugins/{plugin}/assets/css/{plugin}.css`, **declared in `plugin.json`**, loaded
  **only while the plugin is active**. It builds on the `--jy-*` tokens and kit, but
  its own rules are namespaced under the plugin (`.jy-{plugin}-*`) and unload on
  deactivation — never orphaning into core. (Turns `inbound_email`'s hand-rolled
  `mailbox_reader.css` into the standard pattern.)
- **Theme-provided components** are theme-scoped → styled in the theme's stylesheet.

**Loading order:** kit → active plugin stylesheets → theme (theme has final say;
plugins load after the kit so they can use its tokens).

**Plugin CSS loading = global-when-active.** Loads on every page while active, not at
all when inactive. Page-scoped loading is a possible later optimization.

**The component framework itself.** `ComponentRenderer` injects a one-time `<style>`
block and writes inline `style=` for layout width/height. Its static layout rules
move into a `.jy-cl-*` kit section; the only inline it emits becomes a
custom-property value (`style="--jy-cl-max-width: …"`) — the sanctioned
server-computed-value case.

## FormWriter

FormWriter emits bare `.btn`/`.form-control`/`.form-group` — exactly the classes the
kit styles under `.jy-ui`. So there is nothing to change in FormWriter: the only
requirement is that **forms render inside a `.jy-ui` scope**, where the kit picks
them up. (Outside `.jy-ui` — e.g. in not-yet-adopted admin — the theme's own form CSS
styles them, so nothing breaks before adoption.)

## Buttons

- **Submits a form** → FormWriter `submitbutton()` (emits `.btn .btn-primary`,
  styled by the kit inside `.jy-ui`).
- **Drives JS only** → a plain `<button type="button" class="btn btn-…">` inside a
  `.jy-ui` scope.
- Never inline button colors. A dialog button is a kit `.btn*` inside a modal — there
  is no separate `.dialog-btn` family (the modal container owns button layout).

**Inline vs. runtime styling.** The "no inline `style=`" rule bans *authored*
`style=` in markup. It does **not** ban JS setting `element.style` at runtime — a
computed popover position, a carousel transform, an animated width can't live in a
stylesheet and are legitimate. Show/hide via `element.style.display` should prefer
toggling a hidden utility, but that is best practice, not a hard rule.

## Loading & cache-busting

- The kit already loads on every page via `render_base_assets()`; nothing new to
  wire. Verify it loads **before** the active theme's own stylesheet so themes
  override via the cascade.
- Switch the base assets' hand-bumped `?v=` to **`filemtime()`** (getjoinery also
  hardcodes `?v=9`). The edge CDN was tested and **honors the query string in its
  cache key**, so `filemtime` busting works through Cloudflare. (Corrects the older
  `reference_dev_cdn_asset_cache` note.)
- **Every page must route through `public_header_common()`** for the kit to be
  guaranteed — including error/maintenance pages. `display_404_page()` `require`s its
  own theme template (with a plain-text fallback); verify those paths load the kit.

## base.css

`base.css` is a **separate grid / responsive / Canvas-compat utility layer**, not a
duplicate of the kit. It is **not** to be blindly folded into `joinery-styles.css`
and retired (that was a v1 assumption). Audit it independently later; out of scope
for the kit-adoption work here.

## Out of scope

Explicitly out of scope (so they aren't mistaken for oversights, and guardrails skip
them):

- **HTML email** — clients strip `<link>`/`<style>`; email templates require inline
  CSS and never load the kit. Governed separately.
- **User-authored / WYSIWYG content** (rich-text fields, `custom_html`). Stored user
  HTML carries its own inline styles/classes; the policy doesn't apply.

## Admin interface

Admin runs the vanilla-HTML5 joinery-system theme and **does not yet use `.jy-ui`**
(0 adoption). Adopting the kit in admin — wrapping pages in `.jy-ui`, using kit
components, dropping admin inline styles, and setting joinery-system's brand tokens —
is the largest single adoption chunk. No framework removal is involved (there is no
UIkit/Bootstrap in the live admin).

## Migration plan

An **adoption plan**, not an authoring one: bring the surfaces that don't yet use the
kit (admin, components, plugins) onto it, sweep inline styles, and fill the kit gaps
(modal). Sequenced with the existing bare-class CSS kept as the transitional layer
until a final cleanup. Full phased sequence, scope counts, and guardrails:
**[css_migration_plan.md](css_migration_plan.md)**. No phase touches the database.

## Verification

- **Visual spot-check across a sample of themes** (getjoinery, zoukroom, linka which
  loads Bootstrap) after each adoption pass — buttons, forms, modals, calendar render
  correctly inside `.jy-ui`.
- **Grep guards**: no `style="` and no `<style>` block in platform views (narrow
  listed exception for server-computed values); email + user-content areas excluded.
- **Confirm the kit reaches every page**, including 404/error/maintenance.
- Existing suites (`tests/`) continue to pass.

## Documentation

The `.jy-ui` kit is **already documented** in
`docs/theme_integration_instructions.md` ("Default Theme CSS Kit & `.jy-ui`
Namespace"). Extend that doc as the kit grows (new components, the modal, the
adoption rules: wrap content in `.jy-ui`, no inline `style=`, no in-view `<style>`),
and cross-link from `docs/formwriter.md`. Docs describe the end state only — no
migration narration.

## Open decisions

1. **Where the contract lives.** ✅ *Decided:* the existing `joinery-styles.css`
   `.jy-ui` kit, loaded by `render_base_assets()`. `base.css` is a separate utility
   layer, left as-is (not folded/retired).
2. **Namespacing.** ✅ *Decided (revised):* `.jy-ui` **ancestor scope** for kit
   components + `.jy-*` classes for chrome + `--jy-*` tokens. **Not** a per-class
   `.jy-btn` prefix (that would duplicate the existing kit).
3. **Admin.** ✅ *Decided:* not special — a Layer 2 theme (joinery-system) that
   adopts the kit; no framework-removal track.
4. **Dialog buttons.** ✅ *Decided:* no separate `.dialog-btn` family — a dialog
   button is a kit `.btn*` inside a modal; the modal container owns button layout.

## Files

**New**
- `plugins/{plugin}/assets/css/{plugin}.css` (+ `plugin.json` declaration) — per-plugin stylesheet
- modal/dialog component section added to `joinery-styles.css`

**Modified**
- `assets/css/joinery-styles.css` — fill gaps (modal), absorb component `<style>` block rules, add feature sections (e.g. `.jy-calendar-grid`, `.jy-cl-*`)
- `includes/PublicPageBase.php` — `filemtime` cache-busting; confirm kit loads before theme stylesheet
- `includes/ComponentRenderer.php` — replace injected `<style>`/inline with a `.jy-cl-*` kit section + custom-property inline
- `includes/PluginManager.php` / `includes/PluginHelper.php` — load active plugins' declared stylesheet (global-when-active), after the kit, before the theme
- error/404 template(s) — route through `public_header_common()`
- Platform views/components (calendar first) — wrap in `.jy-ui`, inline-style sweep, `<style>`-block removal into the kit
- `adm/*` — wrap in `.jy-ui`, adopt kit components, inline-style sweep
- `theme/joinery-system`, `theme/getjoinery` — set brand `--jy-*` overrides; retire duplicated component CSS
- `docs/theme_integration_instructions.md`, `docs/formwriter.md` — extend kit docs / cross-link
