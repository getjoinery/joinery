# CSS Adoption Plan — Finish Rolling Out the `.jy-ui` Kit

**Status:** Active — **Phases 1 ✅, 2 ✅, and 3 ✅ complete; Phases 4–7 pending.** (Phase 3 is
the light, opt-in admin adoption: kit is loadable + brand tokens set + verified; ongoing
per-page opt-in continues as the default for admin work.)
**Version:** 2.1
**Policy:** Implements [css_platform_style_contract.md](css_platform_style_contract.md). Read it first — the platform already ships the `.jy-ui` kit (tokens + `.jy-ui`-scoped components + `.jy-*` chrome), loaded on every page by `render_base_assets()`. This document is *how we finish adopting it* across the surfaces that don't use it yet.

## What this is (and isn't)

This is **adoption**, not authoring. The kit exists and is proven on ~49 public
views. The work is:

1. **Fill the kit gaps** — the system modal (`JoineryModal`) and `filemtime`
   cache-busting. ✅ *Done in Phase 1.*
2. **Bring the non-adopting surfaces onto the kit** — components (✅ *done in Phase 2*),
   then admin and plugins — by wrapping their content in `.jy-ui` and using kit classes.
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
| Promote `JoineryModal` to kit (single copy in `base.js`, loaded on every theme incl. admin); cache-bust → filemtime | small | 1 |
| Modal implementations to converge onto `JoineryModal` | calendar editor (consent self-contained, out of scope) | 2 |
| Component templates onto the kit (`views/components/`) | 15 in-scope (7 with `<style>` blocks, 50 inline) + `ComponentRenderer`; `custom_html` excluded (user content) | 2 |
| Admin pages on the kit | 0 of ~48 (+ ~422 inline styles) | 3 |
| Public views: inline styles to sweep (already `.jy-ui`) | ~699 across 46 files (excludes `views/components/`, now in Phase 2) | 4 |
| Plugins on the kit | 0 (+ ~228 inline, 8 `<style>` blocks, 31 files) | 5 |
| Themes with duplicated component CSS to retire | getjoinery, joinery-system (+ a few) | 6 |
| `includes/` + `theme/` inline styles | 169 + 67 | 3–6 (with their surface) |

Inline-style total ≈ **1,635**; it is swept per surface, never a blocking cost.

## Phases

Ordered: foundation/gaps first, then surfaces by reuse and isolation, then guards.

### Phase 1 — Kit gaps + foundation (low risk) — ✅ COMPLETE

- **Promote `JoineryModal` into the kit** (`.jy-ui`-scoped). The system modal —
  `JoineryModal.confirm/alert/prompt`, a native `<dialog>` API — already exists, but
  its JS was duplicated into two themes' `script.js` (`joinery-system` + `getjoinery`)
  and its `.dialog-*` CSS into those themes' `style.css`. It was **not** in the global
  kit, yet `PublicPageBase` emits `JoineryModal.confirm(...)` for delete confirmations
  on *any* theme — so on any other theme that call threw `JoineryModal is undefined`.
  Fix: the modal JS now lives once in the global `base.js`, and its `.dialog-*` CSS in
  `joinery-styles.css` scoped under `.jy-ui` (the created `<dialog>` carries the `jy-ui`
  class so the scoped rules reach it in the top layer). **Both** theme JS copies are
  deleted. The admin theme (`joinery-system`) skips the base *CSS* — it has its own —
  but now loads the shared `base.js`, so it uses the one `JoineryModal` and keeps its
  own dialog styling. This is a *promote + dedup*, not a build, and it fixes the
  cross-theme bug. (The dead generic `[data-modal]`/`.modal-overlay` toggler in
  `assets/js/script.js` — zero markup used it — has already been removed.)
- **Cache-bust → `filemtime()`** on the base assets (and getjoinery's hardcoded
  `?v=9`). The edge CDN keys on the query string, so this busts correctly.
- **Verify the kit loads before the active theme stylesheet**, and that **every page
  routes through `public_header_common()`** — explicitly the 404/error/maintenance
  paths (`display_404_page()` uses its own template + plain-text fallback); fix any
  that bypass it.

**Exit:** `JoineryModal` lives in the global kit (one copy in `base.js`, `.jy-ui`-scoped
CSS), loaded on every theme — admin included; kit CSS loads before theme on every page
incl. error pages; filemtime busting in place. Zero visual change to existing pages.

### Phase 2 — Components (incl. `ComponentRenderer`, calendar first) — ✅ COMPLETE

Bring the component templates onto the kit. The surface is **15 in-scope templates** in
`views/components/` (`custom_html` is excluded — it's a user-authored `echo $html;`
passthrough, WYSIWYG/user content). Of those, **7 carry a `<style>` block** (accordion,
calendar_grid, feature_grid, list_signup, slot_picker, tabs, text_with_image) and **8 carry
only inline styles** (page_title, cta_banner, hero_static, video_embed, image_gallery,
text_block, divider, spacer); **50 inline `style=` total** across the set. None use `.jy-ui`
yet. For each template, in one pass: wrap its markup in a `.jy-ui` scope, switch to kit
classes, move any `<style>` block's rules into `joinery-styles.css` (a delimited feature
section) and delete the block, and absorb its inline styles into kit classes/utilities.
Sweeping inline here — not deferring it to the Phase 4 view sweep — means one visit per
file instead of two. **`ComponentRenderer`** stops injecting a `<style>` block + free-form
inline styles — its layout rules become a `.jy-cl-*` kit section, and the only inline it
emits is a custom-property value (`style="--jy-cl-max-width: …"`).

**Calendar first, as the worked redo:** wrap `/profile/calendar` content in `.jy-ui`,
replace its `<style>` block and the theme-only `.dialog-btn` buttons with the kit's
`JoineryModal` + `.btn`/`.btn-danger`, and drop its inline styles. (Our calendar shipped
non-conformant; this makes it the reference example.)

**Converge bespoke modals onto the content mode.** Hand-rolled rich modals are
duplicated work, so `JoineryModal` first gains a **generic content mode** —
`JoineryModal.open(contentNode, { buttons })` accepting arbitrary DOM and a custom button
set — and the calendar's recurring **scope/delete choosers** fold onto it (their content
is rich: radio groups + multi-button footers the message/input API can't cover). One other
rich modal stays where it is, by design, not as oversight:
- **Cookie consent (`joinery-cc-modal`)** injects its own CSS and runs on every page so the
  GDPR flow is independent of theme/kit load order. Routing it through `JoineryModal` would
  couple a compliance surface to `base.js` — a resilience regression. It stays
  self-contained; that's the right-layer call.

**Collapse the modal's `.dialog-btn-*` onto kit buttons.** Phase 1 promoted `JoineryModal`
verbatim — its buttons still use a `.dialog-btn-cancel`/`-confirm`/`-danger`/`-primary`
family (rules carried into `joinery-styles.css` to preserve the look with zero change).
This phase retires that family per the contract decision (a dialog button *is* a kit
`.btn`/`.btn-danger`, no separate `.dialog-btn` family): switch the button classes the
modal sets in `base.js` to `.btn`/`.btn-secondary`/`.btn-danger`/`.btn-primary`, delete the
`.dialog-btn-*` rules from `joinery-styles.css`, and drop the admin theme's leftover
`.dialog-btn-*` CSS. The kit already ships `.jy-ui .btn-danger`, so it's a class swap.

**Exit:** all 15 in-scope component templates render inside `.jy-ui` with no `<style>`
blocks and no inline `style=`; `ComponentRenderer` emits no `<style>` block and only a
single custom-property inline; calendar uses the kit `JoineryModal` + buttons and its
scope/delete choosers run on the content mode; `JoineryModal` has a content mode. (The
consent modal is intentionally out — see above; it stays self-contained.)

**Verification status (as implemented):**

- ✅ **Modal content mode + `.dialog-btn-*`→`.btn` collapse** — `JoineryModal.open(content, {buttons})` added; confirm/alert/prompt now build kit `.btn`/`.btn-*`. *Verified in browser on getjoinery and the admin theme:* both modes open, kit buttons styled, no JS errors.
- ✅ **`ComponentRenderer`** — `<style>` block removed; layout rules now `.jy-cl-*` in the kit; only inline is the `--jy-cl-*` custom property. *Verified:* PHP syntax + validator clean; exercised live by the calendar (which renders `calendar_grid` through it).
- ✅ **15 component templates** — each wraps in `.jy-ui`, `<style>` blocks moved to `.jy-{component}-*` kit sections, dynamic values via `--jy-*` custom props. *Verified structurally* (standalone render harness: no `<style>`, `.jy-ui` present, no fatals) for all 15. `custom_html` excluded (user content).
- ✅ **Calendar redo** (`/profile/calendar`) — wrapped in `.jy-ui`, `<style>` block moved to a tokenized `.cal-*` kit section, broken `.dialog-btn-*` buttons fixed to kit `.btn`. *Verified live in browser* (screenshot): grid, toolbar, kit-tokened buttons, popover all render; `calendar_grid` styled by the kit. This is the one component **visually** confirmed end-to-end (the others have no live page).
- ✅ **Calendar scope/delete modals → `JoineryModal.open`** — the recurring edit/delete scope choosers are no longer hand-rolled backdrops; their content renders through the kit content mode with kit `.btn` buttons, and the calendar view is now fully free of inline `style=` (display toggles moved to the `hidden` attribute). *Verified live:* the content-mode path (heading + radios + Cancel/Edit kit buttons, reading the chosen radio) opens and closes correctly on `/profile/calendar`.
- ⏸ **Consent (`joinery-cc-modal`) — kept self-contained by design.** `ConsentHelper` injects its own CSS and runs on every page so the GDPR banner works independent of theme/kit load order. Routing its manage-preferences modal through `JoineryModal` would couple consent to `base.js` — a resilience regression for a compliance surface. Leaving it self-contained is the right-layer call, not debt.

> Note: component visual verification is limited to the calendar because the other
> components have no live page instances (only `feature_grid` is placed, and not at a
> routable URL); they were verified structurally via render harness. (At Phase 2 time the
> admin theme did not load the kit stylesheet, so admin previews couldn't confirm them
> either; Phase 3 has since made the kit loadable in admin.)

### Phase 3 — Admin (`adm/`) — *light adoption, not a conversion* — ✅ COMPLETE

**The admin theme stays.** joinery-system keeps owning the admin's look; we are **not**
replacing it or holding admin to the zero-inline bar the public surfaces get. The kit is a
structural vocabulary plus `--jy-*` tokens, not a skin — so admin can draw on the global
styles while the theme's token overrides keep its own identity. This phase is opt-in and
opportunistic, not a full sweep.

**Why lighter here:** admin is internal, has no public/SEO surface, and already renders
fine on its own theme. The payoff of forcing every page into `.jy-ui` and eliminating all
422 inline styles is low relative to the churn. We take the wins that are cheap and leave
the rest.

**In scope (do):**
- **Make the kit *available* in admin (the one enabling change).** `PublicPageJoinerySystem`
  overrides `render_base_assets()` to an empty body, so the kit stylesheet
  (`assets/css/joinery-styles.css`) never links on admin pages — that single fact is what
  blocks `.jy-ui` in admin. (The kit *JS* already loads: Phase 1 put `base.js` in the admin
  footer, so `JoineryModal` already works there; only the CSS is missing.) Fix: override
  `render_base_assets()` to emit **only** the kit stylesheet link —
  `<link rel="stylesheet" href="/assets/css/joinery-styles.css?v={asset_mtime}">`. Do **not**
  load `base.css` (the reset/utility layer the original empty-override comment correctly
  flagged as conflicting with admin's reset), and do **not** re-add `base.js` (already in the
  footer). Cascade lands correctly for free: `global_includes_top()` runs
  `render_base_assets()` → `render_brand_token_overrides()` → then `style.css`, so kit
  defaults load first, brand-token overrides win next, and admin `style.css` wins last.
- **Set joinery-system's brand `--jy-*` overrides** so kit components wear admin's palette
  (its blue `#2A7BE4`) rather than the kit's default slate. The override *plumbing* already
  exists and already runs in admin (`render_brand_token_overrides`), but it is driven by five
  **global** settings (`jy_color_primary`, `jy_color_primary_hover`, `jy_color_primary_text`,
  `jy_color_surface`, `jy_color_bg`) that are currently empty — and being global, setting them
  would also reskin the public theme. For an admin-*specific* palette, add a small
  `:root { --jy-color-primary: #2A7BE4; … }` block to joinery-system's own `style.css`
  (it loads last, so it wins, and admin stays independent of the public site's colors).
- **No leakage to verify away:** loading the kit globally in admin only adds `:root{--jy-*}`
  token *definitions* (new variables; admin's own `--primary`/`--muted`/`--radius` are
  untouched) and `.jy-ui`-scoped component rules (inert until a page opts in). The only other
  rules in the file are `body.jy-default …` (admin's body is `class="preload"`, never matches)
  and distinctive public-chrome classes (`.jy-site-header`, `.jy-panel`, `.jy-cl[...]`) admin
  markup never emits. Existing admin chrome renders identically.
- **Adopt the kit opportunistically** — when building or substantially editing an admin
  page, prefer kit components (`.btn`, form classes) and wrap that page's content in
  `.jy-ui`. New admin work defaults to the kit.
- **Pick off cheap inline-style clusters** where a kit class or utility is a clean
  one-for-one swap. No obligation to chase every occurrence.

**Out of scope (don't):**
- No mandate to wrap all 48 admin pages in `.jy-ui`.
- No requirement to drive admin inline `style=` to zero or remove all 3 `<style>` blocks.
- No restyling of pages that already work, purely for adoption's sake.

**Scope reference (not a target):** ~422 inline across ~48 files; 3 `<style>` blocks
(`admin_spec_view.php`, `admin_plugins.php`, `admin_help.php`). These are the *available*
surface, not a checklist to clear.

**Exit:** the kit is loadable in admin with joinery-system token overrides set; new/edited
admin pages can and do opt into it. Pre-existing admin inline styles and `<style>` blocks
are acceptable to leave in place.

**Progress (the discrete, has-a-done items — opt-in page adoption is ongoing, not tracked here):**

- ✅ **Kit loader** — `render_base_assets()` in `PublicPageJoinerySystem` now links
  `joinery-styles.css` only (not `base.css`/`base.js`). *Verified live on `/admin/admin_users`:*
  the `<link>` is present and the kit `:root` tokens resolve.
- ✅ **Admin brand tokens** — `:root { --jy-color-* }` block added to joinery-system `style.css`,
  mapping the kit tokens onto admin's own `--*` palette. *Verified live:* `--jy-color-primary`
  computes to `#2A7BE4` (admin blue, overriding the kit's default slate), `--jy-radius-md` to
  admin's `--radius`.
- ✅ **Verify chrome unchanged** — `/admin/admin_users` renders identically after the kit loads
  globally (sidebar, breadcrumbs, toolbar, table, blue Add-User button all intact). *Screenshot
  confirmed.* Expected, since every non-`.jy-ui` kit rule is either `body.jy-default`-scoped
  (admin body is `preload`) or a public-chrome class admin never emits.
- ✅ **Verify opt-in works** — a `.jy-ui` probe injected on a live admin page renders
  `.btn-primary` at `rgb(42,123,228)` (= admin blue) with white text and admin's radius.
  *Browser-confirmed:* kit components render on the admin palette once a region opts in.

### Phase 4 — Public views inline sweep

The ~49 public views already opt into `.jy-ui`, so this is mostly removing their
remaining inline `style=` (≈699, excluding `views/components/` which is swept in Phase 2)
in favor of kit classes/utilities, plus wrapping any stragglers not yet in `.jy-ui`.

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
  flight) → admin (light, opt-in adoption on its own theme — internal, low payoff for a
  full sweep) → public-view sweep (where the inline-style payoff actually lands) → plugins
  (modular, one at a time) → theme dedup (needs care to not break chrome).
- **Phase 7** locks it in.

## Files

See the policy spec's Files section: [css_platform_style_contract.md](css_platform_style_contract.md#files).
