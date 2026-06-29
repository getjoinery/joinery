# Spec: Mobile-Responsive Admin Interface

## Status
Active — awaiting implementation.

## Problem

The admin interface (Joinery System theme, `theme/joinery-system/`) is usable on a
phone but feels broken in specific places. Verified at 390px width on
`dev.getjoinery.com`:

- **List pages scroll sideways.** The users list renders ~857px wide inside a
  390px viewport, so the whole page scrolls horizontally — the "Signup Date" and
  "Email Verified" columns are off-screen and unreachable without dragging. This
  is the single biggest contributor to the "not mobile optimized" impression.
- **List control bars overflow.** The "Add User / Sort / direction / Search" row
  sits on one horizontal line that runs past the right edge; the Search button is
  half cut off.
- **The global admin bar wraps and isn't touch-friendly.** The black toolbar
  (`#joinery-admin-bar`, rendered in `includes/PublicPageBase.php`) uses
  `float`/`inline-block` with hover-only dropdowns and no media queries. On a
  phone it wraps to two lines, eats vertical space, and its dropdowns (theme
  switcher, user menu, "+ New") cannot be opened by touch. On admin pages it also
  duplicates controls already present in the admin topbar.
- **Wide content blocks overflow.** Multi-column callouts (e.g. the SSL warning
  banner on `/admin/admin_settings`) run past the right edge.

## What already works (do not rebuild)

- **Viewport meta tag** is present (`PublicPageJoinerySystem.php`).
- **Sidebar drawer** works correctly: below 1200px the sidebar slides off-screen
  and the topbar hamburger (`.topbar-hamburger` → `.sidebar-open`) slides it back
  in over a dimmed `.sidebar-overlay`, with click-to-close. Verified good on phone.
- **Forms stack into a single column.** The settings page — one of the longest
  forms in the app — renders cleanly stacked. Edit forms are largely fine as-is.

This work is therefore **CSS plus the shared table renderer**, not a rebuild.

## Goals

1. No horizontal page scroll on any core admin page at 360–414px width.
2. Admin list tables are readable on a phone without sideways scrolling.
3. The global admin bar is legible and operable by touch on a phone.
4. List-page control bars and wide content blocks stay within the viewport.
5. The desktop experience is unchanged at ≥1200px.

## Breakpoint strategy

Reuse the breakpoints already in `theme/joinery-system/assets/css/style.css`.
The phone target is `max-width: 767px`; the existing `max-width: 480px` handles
the smallest screens. No new breakpoint scale is introduced.

## Workstreams

### 1. Responsive table pattern (primary)

The highest-payoff item. All admin lists render through one seam, so a single fix
propagates everywhere.

- **Where:** the table renderer in `includes/PublicPageBase.php` (the
  `tableheader()` path that emits `<div class="table-responsive"><table>`) and the
  theme's `getTableClasses()` in `includes/PublicPageJoinerySystem.php`.
- **Approach:** below 767px, collapse each row into a stacked card instead of
  relying on `overflow-x: auto`. The renderer stamps each cell with its column
  header (e.g. a `data-label` attribute) so CSS can print "Email:" beside the value
  on mobile. **Show all columns** — every column becomes a label/value line; nothing
  is hidden and no per-page configuration is required. The **first column renders as
  the card heading** (visually emphasized), not as a label/value line.
- **Why show-all + heading:** this is the foundation the denser options build on,
  not a dead end. The header-stamping groundwork is identical for all variants, and
  treating the first column as a "primary" heading means a future *primary +
  tap-to-expand* layer is a purely additive CSS/JS change — nothing gets torn out.
  If pages feel too long in practice, that expand/collapse layer is the follow-on.
- **Constraint:** must work for every admin list without per-page edits. Any list
  that cannot use the stacked pattern falls back to horizontal scroll, contained so
  it does not push the whole page wide.

### 2. List control bar reflow

- **Where:** the list-page header controls (add button, sort field, sort
  direction, search) — emitted by the admin list/table header rendering.
- **Approach:** allow the control row to wrap and let search/controls go
  full-width below 767px. CSS only.

### 3. Global admin bar

- **Where:** `#joinery-admin-bar` markup and inline `<style id="joinery-admin-bar-css">`
  in `includes/PublicPageBase.php`.
- **Approach (resolved):**
  - **On `/admin/*` pages, suppress the admin bar entirely below 767px.** Its
    navigation, user menu, and tools duplicate the admin topbar there, so it is pure
    clutter on a small screen. (Desktop is unchanged — the bar stays at ≥768px.)
  - **On public pages, keep the bar but make it touch-friendly below 767px:** fit it
    to one line (collapse labels to icons or move secondary items into a single
    menu) and replace the hover-only dropdowns with a tap/click toggle so the theme
    switcher, user menu, and "+ New" are reachable by touch. On public pages the bar
    is the only admin entry point, so it must stay usable.
  - Scope the suppression to admin pages via the existing admin-page context
    (admin routes render through `AdminPage` / `PublicPageJoinerySystem`), not via a
    new per-page flag. Note: the `joinery-admin-bar-active` body class is **not** the
    right signal — it marks "an admin is logged in" and is present on public pages
    too. Use the admin-page/layout distinction instead.

### 4. Wide content block containment

- **Where:** card/callout components in `theme/joinery-system/assets/css/style.css`.
- **Approach:** ensure multi-column callouts stack or constrain to viewport width
  below 767px. CSS only.

### 5. Plugin admin pages

Plugins are **in scope**. They split into two groups (inventory below).

**Auto-fixed by workstream 1 — 11 list pages, no extra work.** These render through
the core `tableheader()` renderer and inherit the stacked-card pattern:

- `bookings`: `admin_bookings.php`, `admin_booking_types.php`
- `inbound_email`: `admin_inbound_email.php`, `admin_inbound_email_imap.php`,
  `admin_inbound_email_logs.php`
- `items`: `admin_items.php`, `admin_item_relation_types.php`
- `joinery_ai`: `index.php`, `notes.php`, `runs.php`
- `server_manager`: `jobs.php`, `specs.php`

**Hand-rolled tables — explicit work, mechanical.** Wrap/convert these to the same
responsive pattern (apply the card treatment to list-style tables; for key/value
detail tables, just contain them so they don't overflow):

- `inbound_email`: `admin_inbound_email_filters.php` (dropdown actions in cells),
  `admin_inbound_email_setup.php` (custom `iem-table-*` widths)
- `server_manager`: `index.php` (dashboard accordion + recent-jobs table),
  `targets.php`, `publish_upgrade.php`, `target_info.php`
- `bookings`: `admin_booking.php` (detail view — low priority, contain only)

**Bespoke, deferred — `server_manager/node_detail.php`.** A multi-tab view with six
tables, status badges, inline forms, and its own `.svm-*` CSS. In this spec it is
only **contained**: each tab's tables get horizontal scroll bounded within the tab
so nothing pushes the page wide. It will be usable, not polished. A proper per-tab
mobile layout is a **follow-up spec**, not part of this work.

## Out of scope

- Responsive typography scaling (`clamp()` font sizing).
- Reworking the sidebar drawer (already works).
- Per-page form redesigns (forms already stack acceptably).
- A *primary + tap-to-expand* table card layer — show-all is the target here; the
  expandable layer is a future additive follow-on if pages prove too long.
- A bespoke per-tab mobile layout for `server_manager/node_detail.php` — contained
  here, refactored in a separate follow-up spec.

## Acceptance criteria

Verified in the browser at 390px (and spot-checked at 360px and 414px), logged in
as a permission-10 admin:

- [ ] `/admin/admin_users` (and other core list pages) show no horizontal page
      scroll; every column is readable in the stacked card layout, with the first
      column as the card heading.
- [ ] The list control bar (add/sort/search) is fully visible and usable.
- [ ] The admin bar is **absent** on `/admin/*` pages below 767px; on public pages
      it fits one line and all its dropdowns open by tap.
- [ ] `/admin/admin_settings` shows no horizontal overflow.
- [ ] Every "auto-fixed" plugin list page (e.g. `admin_inbound_email`,
      `server_manager/jobs`) shows the stacked layout with no horizontal scroll.
- [ ] Every "hand-rolled" plugin page is converted/contained: no horizontal page
      scroll.
- [ ] `server_manager/node_detail.php` does not push the page wide on a phone (its
      tab tables scroll within the tab); full polish is explicitly deferred.
- [ ] At ≥1200px every page is pixel-unchanged from current.

## Documentation

Document the responsive table pattern (how cells get their mobile labels, and how
to opt a list out) in `docs/admin_pages.md`, in the table-rendering section. No
new doc file.
